<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Empresa\NotaRequest;
use App\Jobs\TransmitirNota;
use App\Models\Cfop;
use App\Models\Empresa;
use App\Models\Nota;
use App\Services\Nfe\EmissorNfe;
use App\Models\NotaEvento;
use App\Services\ArquivosDoMes;
use App\Support\RejeicoesSefaz;
use App\Services\Nfe\EventosNfe;
use App\Services\Nfe\GeradorDanfe;
use App\Services\Nfe\ImportadorNotaCompra;
use App\Services\Nfe\SalvarNota;
use App\Services\Nfe\ValidadorEmissao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class NotaController extends Controller
{
    public function index(Request $request): View
    {
        $empresa = $this->empresa($request);
        $status = $request->query('status');

        $notas = $empresa->notas()->where('modelo', 55)
            ->when($status && array_key_exists($status, Nota::STATUS), fn ($q) => $q->where('status', $status))
            ->orderByRaw('numero IS NULL DESC')
            ->orderByDesc('numero')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('empresa.notas.index', [
            'notas' => $notas,
            'status' => $status,
            'pendencias' => $empresa->pendenciasParaEmitir(),
        ]);
    }

    public function create(Request $request): View
    {
        $operacao = array_key_exists((string) $request->query('operacao'), Nota::OPERACOES) ? $request->query('operacao') : 'venda';

        return $this->formulario($request, new Nota([
            'operacao' => $operacao,
            'natureza_operacao' => Nota::OPERACOES[$operacao]['natureza'],
            'modalidade_frete' => 9,
            'forma_pagamento' => '01',
            'ind_pagamento' => 0,
        ]));
    }

    public function store(NotaRequest $request, SalvarNota $salvar, ValidadorEmissao $validador, EmissorNfe $emissor): RedirectResponse
    {
        $nota = $salvar->salvar($this->empresa($request), $request->user(), $request->validated());

        return $this->depoisDeSalvar($request, $nota, $validador, $emissor);
    }

    /** Devolução: cria o rascunho a partir do XML da nota de compra. */
    public function importarCompra(Request $request, ImportadorNotaCompra $importador): RedirectResponse
    {
        $request->validate(
            ['xml' => ['required', 'file', 'max:2048']],
            ['xml.required' => 'Escolha o arquivo XML da nota de compra.', 'xml.max' => 'O arquivo XML deve ter no máximo 2 MB.']
        );

        try {
            $nota = $importador->importar($this->empresa($request), $request->user(), (string) file_get_contents($request->file('xml')->getRealPath()));
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['xml' => $e->getMessage()]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors(['xml' => 'Não foi possível montar a devolução com esta nota: '.implode(' ', $e->validator->errors()->all())]);
        }

        return redirect()->route('empresa.notas.edit', $nota->id)
            ->with('sucesso', 'Nota de compra importada. Remova os itens que não vai devolver ou ajuste as quantidades e emita.');
    }

    public function show(Request $request, int $nota): View|RedirectResponse
    {
        if ($this->buscar($request, $nota)->ehNfce()) {
            return redirect()->route('empresa.nfce.show', $nota);
        }
        $registro = $this->buscar($request, $nota)->load(['itens', 'eventos' => fn ($q) => $q->latest('id')]);

        return view('empresa.notas.show', [
            'nota' => $registro,
            'impedimentoCancelamento' => EventosNfe::impedimentoCancelamento($registro),
            'impedimentoCarta' => EventosNfe::impedimentoCarta($registro),
            'problemas' => $registro->podeEditar() ? app(ValidadorEmissao::class)->problemas($registro) : [],
        ]);
    }

    public function edit(Request $request, int $nota): View|RedirectResponse
    {
        $registro = $this->buscar($request, $nota)->load('itens');
        if ($registro->ehNfce()) {
            return redirect()->route('empresa.nfce.edit', $registro->id);
        }

        if (! $registro->podeEditar()) {
            return redirect()->route('empresa.notas.show', $registro->id)
                ->withErrors(['nota' => 'Esta nota não pode mais ser alterada.']);
        }

        return $this->formulario($request, $registro);
    }

    public function update(NotaRequest $request, SalvarNota $salvar, ValidadorEmissao $validador, EmissorNfe $emissor, int $nota): RedirectResponse
    {
        $registro = $this->buscar($request, $nota);
        abort_if($registro->ehNfce(), 404);

        if (! $registro->podeEditar()) {
            return redirect()->route('empresa.notas.show', $registro->id)
                ->withErrors(['nota' => 'Esta nota não pode mais ser alterada.']);
        }

        $registro = $salvar->salvar($this->empresa($request), $request->user(), $request->validated(), $registro);

        return $this->depoisDeSalvar($request, $registro, $validador, $emissor);
    }

    public function transmitir(Request $request, ValidadorEmissao $validador, EmissorNfe $emissor, int $nota): RedirectResponse
    {
        $registro = $this->buscar($request, $nota)->load('itens');
        abort_if($registro->ehNfce(), 404);

        return $this->enviar($registro, $validador, $emissor);
    }

    public function consultar(Request $request, EmissorNfe $emissor, int $nota): RedirectResponse
    {
        $registro = $this->buscar($request, $nota);

        if ($registro->status !== Nota::PROCESSANDO) {
            return redirect()->route('empresa.notas.show', $registro->id);
        }

        $emissor->consultar($registro);

        return redirect()->route('empresa.notas.show', $registro->id);
    }

    public function destroy(Request $request, int $nota): RedirectResponse
    {
        $registro = $this->buscar($request, $nota);

        if (! $registro->podeExcluir()) {
            return back()->withErrors(['nota' => 'Só é possível excluir rascunhos que ainda não foram enviados.']);
        }

        $registro->delete();

        return redirect()->route('empresa.notas.index')->with('sucesso', 'Rascunho excluído.');
    }

    public function xml(Request $request, int $nota): Response
    {
        $registro = $this->buscar($request, $nota);
        abort_unless($registro->xml_path && Storage::disk('local')->exists($registro->xml_path), 404);

        return Storage::disk('local')->download($registro->xml_path, "{$registro->chave}.xml", ['Content-Type' => 'application/xml']);
    }

    public function danfe(Request $request, GeradorDanfe $gerador, int $nota): Response
    {
        $registro = $this->buscar($request, $nota);
        abort_unless($registro->xml_path && Storage::disk('local')->exists($registro->xml_path), 404);

        // NFC-e: o cupom é refeito a cada impressão, para valer a largura de bobina atual.
        // NF-e: gera só se o PDF ainda não existe (falhou na hora da autorização).
        if ($registro->ehNfce() || ! $registro->pdf_path || ! Storage::disk('local')->exists($registro->pdf_path)) {
            $caminho = preg_replace('/procNFe\.xml$/', 'danfe.pdf', $registro->xml_path);
            Storage::disk('local')->put($caminho, $gerador->gerar(Storage::disk('local')->get($registro->xml_path), $registro->status === Nota::CANCELADA,
                $registro->ehNfce(), (int) $registro->empresa->nfce_bobina));
            $registro->update(['pdf_path' => $caminho]);
        }

        return response(Storage::disk('local')->get($registro->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($registro->ehNfce() ? 'NFCe-' : 'DANFE-').$registro->numero.'.pdf"',
        ]);
    }

    /** .zip com os XMLs e DANFEs do mês (para mandar ao contador). */
    public function arquivosDoMes(Request $request, ArquivosDoMes $arquivos): Response|RedirectResponse
    {
        $mes = ArquivosDoMes::mes($request->query('mes'));
        $empresa = $this->empresa($request);
        $zip = $arquivos->gerar(collect([$empresa]), $mes);

        if (! $zip) {
            return back()->with('erro', 'Nenhuma nota autorizada, cancelada ou denegada em '.$mes->format('m/Y').'.');
        }

        return response()->download($zip, 'NFe-'.$empresa->cnpj.'-'.$mes->format('Y-m').'.zip')->deleteFileAfterSend();
    }

    public function cancelar(Request $request, EventosNfe $eventos, int $nota): RedirectResponse
    {
        $registro = $this->buscar($request, $nota);
        $dados = $request->validate(
            ['justificativa' => ['required', 'string', 'min:15', 'max:255']],
            ['justificativa.min' => 'Escreva o motivo do cancelamento com pelo menos 15 caracteres.', 'justificativa.required' => 'Escreva o motivo do cancelamento.'],
        );

        try {
            $evento = $eventos->cancelar($registro, $this->textoLimpo($dados['justificativa']), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['cancelamento' => $e->getMessage()]);
        }

        return $evento->status === 'registrado'
            ? redirect()->route($registro->ehNfce() ? 'empresa.nfce.show' : 'empresa.notas.show', $registro->id)
                ->with('sucesso', $registro->ehNfce() ? 'NFC-e cancelada na SEFAZ.' : 'Nota cancelada na SEFAZ. O DANFE agora sai com a tarja de cancelada.')
            : back()->withInput()->withErrors(['cancelamento' => (RejeicoesSefaz::frase($evento->codigo_status) ? RejeicoesSefaz::frase($evento->codigo_status).' ' : '')
                .'(A SEFAZ não aceitou o cancelamento'.($evento->codigo_status ? ", código {$evento->codigo_status}" : '').': '.$evento->motivo.')']);
    }

    public function corrigir(Request $request, EventosNfe $eventos, int $nota): RedirectResponse
    {
        $registro = $this->buscar($request, $nota);
        $dados = $request->validate(
            ['correcao' => ['required', 'string', 'min:15', 'max:1000']],
            ['correcao.min' => 'Descreva a correção com pelo menos 15 caracteres.', 'correcao.required' => 'Descreva a correção.'],
        );

        try {
            $evento = $eventos->corrigir($registro, $this->textoLimpo($dados['correcao']), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['correcao' => $e->getMessage()]);
        }

        return $evento->status === 'registrado'
            ? redirect()->route('empresa.notas.show', $registro->id)->with('sucesso', "Carta de correção nº {$evento->sequencia} registrada na SEFAZ.")
            : back()->withInput()->withErrors(['correcao' => (RejeicoesSefaz::frase($evento->codigo_status) ? RejeicoesSefaz::frase($evento->codigo_status).' ' : '')
                .'(A SEFAZ não aceitou a carta de correção'.($evento->codigo_status ? ", código {$evento->codigo_status}" : '').': '.$evento->motivo.')']);
    }

    public function eventoXml(Request $request, int $nota, int $evento): Response
    {
        $registro = $this->eventoDaNota($request, $nota, $evento);

        return Storage::disk('local')->download($registro->xml_path, basename($registro->xml_path), ['Content-Type' => 'application/xml']);
    }

    public function eventoPdf(Request $request, EventosNfe $eventos, int $nota, int $evento): Response
    {
        $registro = $this->eventoDaNota($request, $nota, $evento);

        return response($eventos->pdf($registro), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($registro->tipo === EventosNfe::CARTA_CORRECAO ? 'CCe-'.$registro->sequencia : 'Cancelamento').'-NFe-'.$registro->nota->numero.'.pdf"',
        ]);
    }

    private function eventoDaNota(Request $request, int $nota, int $evento): NotaEvento
    {
        $registro = $this->buscar($request, $nota)->eventos()->where('status', 'registrado')->findOrFail($evento);
        abort_unless($registro->xml_path && Storage::disk('local')->exists($registro->xml_path), 404);

        return $registro;
    }

    /** Texto do evento: sem quebras de linha nem espaços repetidos. */
    private function textoLimpo(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    private function depoisDeSalvar(NotaRequest $request, Nota $nota, ValidadorEmissao $validador, EmissorNfe $emissor): RedirectResponse
    {
        if ($request->input('acao') === 'emitir') {
            return $this->enviar($nota, $validador, $emissor);
        }

        return redirect()->route('empresa.notas.show', $nota->id)->with('sucesso', 'Rascunho salvo.');
    }

    private function enviar(Nota $nota, ValidadorEmissao $validador, EmissorNfe $emissor): RedirectResponse
    {
        $problemas = $validador->problemas($nota);

        if ($problemas) {
            return redirect()->route('empresa.notas.show', $nota->id)->withErrors(['emissao' => $problemas]);
        }

        try {
            $nota = $emissor->preparar($nota);
        } catch (\RuntimeException $e) {
            return redirect()->route('empresa.notas.show', $nota->id)->withErrors(['emissao' => [$e->getMessage()]]);
        }

        TransmitirNota::dispatch($nota);

        return redirect()->route('empresa.notas.show', $nota->id)->with('sucesso', 'Nota enviada para a SEFAZ.');
    }

    private function formulario(Request $request, Nota $nota): View
    {
        $empresa = $this->empresa($request);

        $produtos = $empresa->produtos()->orderBy('descricao')->get()->map(fn ($p) => [
            'id' => $p->id,
            'codigo' => $p->codigo,
            'descricao' => $p->descricao,
            'unidade' => $p->unidade,
            'preco' => (float) $p->valor_unitario,
            'cfop' => $p->cfop_padrao,
        ]);

        $itens = old('itens', $nota->exists
            ? $nota->itens->map(function ($i) {
                $moeda = fn ($v) => (float) $v > 0 ? number_format((float) $v, 2, ',', '.') : '';

                return [
                    'produto_id' => $i->produto_id,
                    'codigo' => $i->codigo,
                    'descricao' => $i->descricao,
                    'ncm' => $i->ncm,
                    'cest' => $i->cest,
                    'gtin' => $i->gtin,
                    'unidade' => $i->unidade,
                    'origem' => $i->origem,
                    'entrada' => $i->tributos_extras['entrada'] ?? null,
                    'quantidade' => rtrim(rtrim(number_format((float) $i->quantidade, 4, ',', ''), '0'), ','),
                    'valor_unitario' => number_format((float) $i->valor_unitario, 2, ',', '.'),
                    'desconto' => $moeda($i->valor_desconto),
                    'cfop' => Cfop::formatar($i->cfop),
                    'base_icms' => $moeda($i->base_icms),
                    'aliq_icms' => $moeda($i->aliq_icms),
                    'valor_icms' => $moeda($i->valor_icms),
                    'base_icms_st' => $moeda($i->base_icms_st),
                    'aliq_icms_st' => $moeda($i->aliq_icms_st),
                    'valor_icms_st' => $moeda($i->valor_icms_st),
                    'valor_ipi_devolvido' => $moeda($i->valor_ipi_devolvido),
                ];
            })->all()
            : []);

        $referencias = old('referencias', $nota->referencias ?? []);

        return view('empresa.notas.form', [
            'nota' => $nota,
            'referencias' => $referencias ?: [['tipo' => 'nfe', 'chave' => '']],
            'ufs' => \App\Http\Requests\Empresa\AtualizarDadosRequest::UFS,
            'empresa' => $empresa,
            'pessoas' => $empresa->destinatarios()->orderBy('razao_social')->get(),
            'produtos' => $produtos,
            'itens' => $itens,
        ]);
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }

    private function buscar(Request $request, int $id): Nota
    {
        return $this->empresa($request)->notas()->findOrFail($id);
    }
}
