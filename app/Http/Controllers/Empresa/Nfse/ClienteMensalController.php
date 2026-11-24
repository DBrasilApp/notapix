<?php

namespace App\Http\Controllers\Empresa\Nfse;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Nfse;
use App\Models\NfseContrato;
use App\Support\NumeroBr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Clientes mensais: quem recebe a mesma NFS-e todo mês (base da emissão em lote). */
class ClienteMensalController extends Controller
{
    public function index(Request $request): View
    {
        $contratos = $this->empresa($request)->nfseContratos()->with(['tomador', 'servico'])
            ->orderByDesc('ativo')->get()
            ->sortBy(fn ($c) => [! $c->ativo, mb_strtolower((string) $c->tomador?->razao_social)])->values();

        return view('empresa.nfse.clientes.index', ['contratos' => $contratos]);
    }

    public function create(Request $request): View
    {
        return $this->formulario($request, new NfseContrato(['ativo' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->empresa($request)->nfseContratos()->create($this->validar($request));

        return redirect()->route('empresa.nfse.clientes.index')->with('sucesso', 'Cliente mensal cadastrado.');
    }

    public function edit(Request $request, int $cliente): View
    {
        return $this->formulario($request, $this->buscar($request, $cliente));
    }

    public function update(Request $request, int $cliente): RedirectResponse
    {
        $this->buscar($request, $cliente)->update($this->validar($request));

        return redirect()->route('empresa.nfse.clientes.index')->with('sucesso', 'Cliente mensal atualizado.');
    }

    public function destroy(Request $request, int $cliente): RedirectResponse
    {
        $this->buscar($request, $cliente)->delete();

        return redirect()->route('empresa.nfse.clientes.index')->with('sucesso', 'Cliente removido da emissão mensal. As notas já emitidas não são afetadas.');
    }

    private function validar(Request $request): array
    {
        $empresaId = $this->empresa($request)->id;
        $request->merge(['valor' => NumeroBr::paraBanco($request->input('valor'))]);
        foreach (array_keys(Nfse::RETENCOES) as $campo) {
            $request->merge(["pct_{$campo}" => NumeroBr::paraBanco($request->input("pct_{$campo}"))]);
        }

        $dados = $request->validate([
            'destinatario_id' => ['required', 'integer', Rule::exists('destinatarios', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at')],
            'servico_id' => ['required', 'integer', Rule::exists('servicos', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at')],
            'valor' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'discriminacao' => ['nullable', 'string', 'max:2000'],
            'pct_ret_inss' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'pct_ret_irrf' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'pct_ret_csll' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'pct_ret_pis' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'pct_ret_cofins' => ['nullable', 'numeric', 'min:0', 'max:30'],
        ], [
            'destinatario_id.required' => 'Escolha o cliente (tomador).',
            'servico_id.required' => 'Escolha o serviço.',
            'valor.gt' => 'Informe o valor mensal.',
        ]);

        $retencoes = [];
        foreach (array_keys(Nfse::RETENCOES) as $campo) {
            if ((float) ($dados["pct_{$campo}"] ?? 0) > 0) {
                $retencoes[$campo] = (float) $dados["pct_{$campo}"];
            }
            unset($dados["pct_{$campo}"]);
        }

        return $dados + [
            'discriminacao' => filled($dados['discriminacao'] ?? null) ? trim($dados['discriminacao']) : null,
            'iss_retido' => $request->boolean('iss_retido'),
            'ativo' => $request->boolean('ativo'),
            'retencoes' => $retencoes ?: null,
        ];
    }

    private function formulario(Request $request, NfseContrato $contrato): View
    {
        $empresa = $this->empresa($request);

        return view('empresa.nfse.clientes.form', [
            'contrato' => $contrato,
            'empresa' => $empresa,
            'servicos' => $empresa->servicos()->orderBy('nome')->get(),
            'tomadores' => $empresa->destinatarios()->orderBy('razao_social')->get(['id', 'razao_social', 'nome_fantasia', 'cpf_cnpj', 'tipo_pessoa']),
        ]);
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }

    private function buscar(Request $request, int $id): NfseContrato
    {
        return $this->empresa($request)->nfseContratos()->findOrFail($id);
    }
}
