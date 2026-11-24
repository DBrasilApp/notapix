<?php

namespace App\Http\Controllers\Empresa\Nfse;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Nfse;
use App\Services\Nfse\EmissorNfse;
use App\Services\Nfse\SalvarNfse;
use App\Services\Nfse\ValidadorNfse;
use App\Support\NumeroBr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** NFS-e Nacional: emissão avulsa, consulta, cancelamento, XML e PDF. */
class NfseController extends Controller
{
    public function index(Request $request): View
    {
        $empresa = $this->empresa($request);
        $competencia = (string) $request->query('competencia', '');
        $lote = (string) $request->query('lote', '');

        $notas = $empresa->nfses()
            ->when(preg_match('/^\d{4}-\d{2}$/', $competencia), fn ($q) => $q->whereDate('competencia', $competencia.'-01'))
            ->when($lote !== '', fn ($q) => $q->where('lote', $lote))
            ->when($request->query('situacao'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($lote !== '' ? 200 : 30)
            ->withQueryString();

        return view('empresa.nfse.index', [
            'notas' => $notas,
            'competencia' => $competencia,
            'lote' => $lote,
            'processando' => $lote !== '' && $notas->getCollection()->contains(fn ($n) => $n->status === Nfse::PROCESSANDO),
        ]);
    }

    public function create(Request $request): View
    {
        $empresa = $this->empresa($request);
        $nfse = new Nfse(['competencia' => now()->startOfMonth()]);

        // "Emitir outra igual": copia uma nota anterior
        if ($copiar = $request->integer('copiar')) {
            $origem = $empresa->nfses()->findOrFail($copiar);
            $nfse->fill($origem->only([
                'servico_id', 'destinatario_id', 'discriminacao', 'informacoes_complementares', 'valor_servico', 'iss_retido',
                'ret_inss', 'ret_irrf', 'ret_csll', 'ret_pis', 'ret_cofins',
            ]));
        } elseif ($servicoId = $request->integer('servico')) {
            $servico = $empresa->servicos()->findOrFail($servicoId);
            $nfse->fill(['servico_id' => $servico->id, 'discriminacao' => $servico->discriminacao,
                'valor_servico' => $servico->valor, 'iss_retido' => $servico->iss_retido]);
        }
        if ($tomador = $request->integer('tomador')) {
            $nfse->destinatario_id = $empresa->destinatarios()->findOrFail($tomador)->id;
        }

        return $this->formulario($empresa, $nfse);
    }

    public function store(Request $request, SalvarNfse $salvar, ValidadorNfse $validador, EmissorNfse $emissor): RedirectResponse
    {
        $empresa = $this->empresa($request);
        $nfse = $salvar->salvar($empresa, $request->user(), $this->validar($request));

        return $this->emitir($nfse, $validador, $emissor, novo: true);
    }

    public function edit(Request $request, int $nfse): View|RedirectResponse
    {
        $registro = $this->buscar($request, $nfse);
        if (! $registro->podeEditar()) {
            return redirect()->route('empresa.nfse.show', $registro->id);
        }

        return $this->formulario($this->empresa($request), $registro);
    }

    public function update(Request $request, SalvarNfse $salvar, ValidadorNfse $validador, EmissorNfse $emissor, int $nfse): RedirectResponse
    {
        $registro = $this->buscar($request, $nfse);
        if (! $registro->podeEditar()) {
            return redirect()->route('empresa.nfse.show', $registro->id);
        }

        $registro = $salvar->salvar($this->empresa($request), $request->user(), $this->validar($request), $registro);

        return $this->emitir($registro, $validador, $emissor, novo: false);
    }

    public function show(Request $request, int $nfse): View
    {
        return view('empresa.nfse.show', ['nfse' => $this->buscar($request, $nfse)->load('servico')]);
    }

    public function consultar(Request $request, EmissorNfse $emissor, int $nfse): RedirectResponse
    {
        $registro = $this->buscar($request, $nfse);
        $registro = $emissor->consultar($registro);

        return redirect()->route('empresa.nfse.show', $registro->id)
            ->with($registro->status === Nfse::AUTORIZADA ? 'sucesso' : 'aviso', $registro->status === Nfse::AUTORIZADA ? 'NFS-e encontrada e autorizada.' : $registro->motivo);
    }

    public function cancelar(Request $request, EmissorNfse $emissor, int $nfse): RedirectResponse
    {
        $registro = $this->buscar($request, $nfse);
        $dados = $request->validate([
            'motivo' => ['required', Rule::in(['1', '2', '9'])],
            'justificativa' => ['required', 'string', 'min:15', 'max:255'],
        ], [
            'justificativa.min' => 'Explique o motivo com pelo menos 15 letras.',
        ]);

        [$ok, $mensagem] = $emissor->cancelar($registro, (int) $dados['motivo'], $dados['justificativa']);

        return redirect()->route('empresa.nfse.show', $registro->id)->with($ok ? 'sucesso' : 'erro', $mensagem);
    }

    public function email(Request $request, EmissorNfse $emissor, int $nfse): RedirectResponse
    {
        $registro = $this->buscar($request, $nfse);
        $dados = $request->validate(['emails' => ['required', 'string', 'max:500']], ['emails.required' => 'Informe pelo menos um e-mail.']);

        $emails = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $dados['emails']))));
        $invalidos = array_filter($emails, fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($invalidos) {
            return back()->withInput()->withErrors(['emails' => 'E-mail inválido: '.implode(', ', $invalidos)]);
        }

        [$ok, $mensagem] = $emissor->enviarEmail($registro, array_slice($emails, 0, 5));

        return back()->with($ok ? 'sucesso' : 'erro', $mensagem);
    }

    public function xml(Request $request, int $nfse): Response
    {
        $registro = $this->buscar($request, $nfse);
        abort_unless($registro->xml_path && Storage::disk('local')->exists($registro->xml_path), 404);

        return Storage::disk('local')->download($registro->xml_path, "NFSe-{$registro->chave}.xml", ['Content-Type' => 'application/xml']);
    }

    public function pdf(Request $request, EmissorNfse $emissor, int $nfse): Response
    {
        $registro = $this->buscar($request, $nfse);
        abort_unless($registro->xml_path && Storage::disk('local')->exists($registro->xml_path), 404);

        if (! $registro->pdf_path || ! Storage::disk('local')->exists($registro->pdf_path)) {
            abort_unless($emissor->gerarPdf($registro), 500, 'Não foi possível gerar o PDF.');
            $registro->refresh();
        }

        return response(Storage::disk('local')->get($registro->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="NFSe-'.($registro->numero_nfse ?? $registro->id).'.pdf"',
        ]);
    }

    private function emitir(Nfse $nfse, ValidadorNfse $validador, EmissorNfse $emissor, bool $novo): RedirectResponse
    {
        $problemas = $validador->problemas($nfse->load('empresa'));

        if ($problemas) {
            if ($novo && $nfse->numero_dps === null) {
                $nfse->delete(); // não deixa rascunho de uma nota que nem pôde ser enviada
            }

            return back()->withInput()->withErrors(['emissao' => $problemas]);
        }

        try {
            $nfse = $emissor->preparar($nfse);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['emissao' => [$e->getMessage()]]);
        }

        $nfse = $emissor->transmitir($nfse);

        return redirect()->route('empresa.nfse.show', $nfse->id)
            ->with($nfse->status === Nfse::AUTORIZADA ? 'sucesso' : 'aviso', $nfse->status === Nfse::AUTORIZADA ? 'NFS-e emitida.' : null);
    }

    private function validar(Request $request): array
    {
        $campos = ['valor_servico', 'ret_inss', 'ret_irrf', 'ret_csll', 'ret_pis', 'ret_cofins'];
        $request->merge(collect($campos)->mapWithKeys(fn ($c) => [$c => NumeroBr::paraBanco($request->input($c))])->all()
            + ['iss_retido' => $request->boolean('iss_retido')]);

        $empresaId = $this->empresa($request)->id;

        $dados = $request->validate([
            'servico_id' => ['required', 'integer', Rule::exists('servicos', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at')],
            // 0 = sem tomador identificado (escolhido de propósito)
            'destinatario_id' => ['required', 'integer', function ($attr, $valor, $fail) use ($empresaId) {
                if ((int) $valor !== 0 && ! \App\Models\Destinatario::where('empresa_id', $empresaId)->whereKey($valor)->exists()) {
                    $fail('Cliente não encontrado.');
                }
            }],
            'competencia' => ['required', 'date_format:Y-m'],
            'valor_servico' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'discriminacao' => ['required', 'string', 'max:2000'],
            'informacoes_complementares' => ['nullable', 'string', 'max:1500'],
            'iss_retido' => ['boolean'],
            'ret_inss' => ['nullable', 'numeric', 'min:0'],
            'ret_irrf' => ['nullable', 'numeric', 'min:0'],
            'ret_csll' => ['nullable', 'numeric', 'min:0'],
            'ret_pis' => ['nullable', 'numeric', 'min:0'],
            'ret_cofins' => ['nullable', 'numeric', 'min:0'],
        ], [
            'servico_id.required' => 'Escolha o serviço prestado.',
            'destinatario_id.required' => 'Escolha o cliente (tomador) ou "Sem tomador identificado".',
            'valor_servico.gt' => 'Informe o valor do serviço.',
            'discriminacao.required' => 'Descreva o serviço prestado.',
            'competencia.date_format' => 'Informe o mês da competência.',
        ], [
            'valor_servico' => 'valor do serviço',
            'discriminacao' => 'descrição do serviço',
        ]);

        $dados['destinatario_id'] = (int) $dados['destinatario_id'] ?: null;

        return $dados;
    }

    private function formulario(Empresa $empresa, Nfse $nfse): View
    {
        return view('empresa.nfse.form', [
            'nfse' => $nfse,
            'empresa' => $empresa,
            'pendencias' => $empresa->pendenciasNfse(),
            'servicos' => $empresa->servicos()->orderBy('nome')->get(),
            'tomadores' => $empresa->destinatarios()->orderBy('razao_social')
                ->get(['id', 'razao_social', 'nome_fantasia', 'cpf_cnpj', 'tipo_pessoa', 'cep', 'logradouro', 'bairro', 'codigo_municipio']),
        ]);
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }

    private function buscar(Request $request, int $id): Nfse
    {
        return $this->empresa($request)->nfses()->findOrFail($id);
    }
}
