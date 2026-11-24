<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Empresa\AtualizarDadosRequest;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Services\LocalizacaoIbge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DadosController extends Controller
{
    public function edit(Request $request): View
    {
        return view('empresa.dados', [
            'empresa' => $request->user()->empresa,
            'ufs' => AtualizarDadosRequest::UFS,
        ]);
    }

    public function update(AtualizarDadosRequest $request, LocalizacaoIbge $ibge): RedirectResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->user()->empresa;
        $dados = $request->validated();

        // O código IBGE normalmente vem do CEP. Se não veio (CEP genérico, ViaCEP fora do ar),
        // tenta descobrir pelo nome da cidade.
        if (empty($dados['codigo_municipio'])) {
            $dados['codigo_municipio'] = $ibge->codigoMunicipio($dados['uf'], $dados['municipio']);

            if (! $dados['codigo_municipio']) {
                return back()->withInput()->withErrors([
                    'municipio' => 'Não encontramos esta cidade na lista do IBGE. Confira o nome e a UF.',
                ]);
            }
        }

        // Não permite voltar a numeração para trás de notas já usadas
        $maiorUsado = $empresa->notas()->where('modelo', 55)
            ->where('serie', $dados['serie_nfe'])
            ->where('ambiente', $empresa->ambiente)
            ->max('numero');

        if ($maiorUsado && $dados['proximo_numero_nfe'] <= $maiorUsado) {
            return back()->withInput()->withErrors([
                'proximo_numero_nfe' => "O NotaPix já emitiu a nota nº {$maiorUsado} nesta série. Informe {$maiorUsado} ou um número maior como última nota.",
            ]);
        }

        if (isset($dados['nfce_proximo_numero'])) {
            $maiorNfce = $empresa->notas()->where('modelo', 65)->where('serie', $dados['nfce_serie'] ?? $empresa->nfce_serie)
                ->where('ambiente', $empresa->ambiente)->max('numero');
            if ($maiorNfce && $dados['nfce_proximo_numero'] <= $maiorNfce) {
                return back()->withInput()->withErrors([
                    'nfce_ultimo_numero' => "O NotaPix já emitiu a NFC-e nº {$maiorNfce} nesta série. Informe {$maiorNfce} ou um número maior.",
                ]);
            }
        }

        if (isset($dados['nfse_proximo_numero'])) {
            $maiorDps = $empresa->nfses()->where('serie', (string) ($dados['nfse_serie'] ?? $empresa->nfse_serie))
                ->where('ambiente', $empresa->ambiente)->max('numero_dps');
            if ($maiorDps && $dados['nfse_proximo_numero'] <= $maiorDps) {
                return back()->withInput()->withErrors([
                    'nfse_ultimo_numero' => "O NotaPix já enviou a DPS nº {$maiorDps} nesta série. Informe {$maiorDps} ou um número maior.",
                ]);
            }
        }

        // CSC em branco: mantém o que já estava guardado ("Remover" apaga o código e o ID)
        foreach (['homologacao', 'producao'] as $amb) {
            if ($request->boolean("nfce_remover_csc_{$amb}")) {
                $dados["nfce_csc_{$amb}"] = null;
                $dados["nfce_csc_id_{$amb}"] = null;
            } elseif (empty($dados["nfce_csc_{$amb}"])) {
                unset($dados["nfce_csc_{$amb}"]);
            }
        }

        if (! $empresa->podeAlterarCnpj() || empty($dados['cnpj'])) {
            unset($dados['cnpj']);
        }

        $empresa->fill($dados);
        $alterados = array_keys($empresa->getDirty());
        $trocouCnpj = $empresa->isDirty('cnpj');
        $cnpjAnterior = $empresa->getOriginal('cnpj');
        $empresa->save();

        // Certificado de outra raiz de CNPJ deixa de servir: é removido
        if ($trocouCnpj && substr($cnpjAnterior, 0, 8) !== substr($empresa->cnpj, 0, 8)) {
            $empresa->certificados()->delete();
        }

        if ($alterados) {
            Auditoria::registrar('empresa_dados_alterados', $empresa, ['campos' => $alterados]
                + ($trocouCnpj ? ['cnpj_anterior' => $cnpjAnterior] : []));
        }

        return redirect()->route('empresa.dados')->with('sucesso', 'Dados da empresa salvos.');
    }
}
