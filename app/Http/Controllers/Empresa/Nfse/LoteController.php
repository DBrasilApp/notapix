<?php

namespace App\Http\Controllers\Empresa\Nfse;

use App\Http\Controllers\Controller;
use App\Jobs\TransmitirNfse;
use App\Models\Empresa;
use App\Models\Nfse;
use App\Models\NfseContrato;
use App\Services\Nfse\EmissorNfse;
use App\Services\Nfse\SalvarNfse;
use App\Services\Nfse\ValidadorNfse;
use App\Support\NumeroBr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Emissão mensal em lote: uma NFS-e para cada cliente mensal marcado, na competência escolhida.
 * Também faz o reajuste anual dos valores (ex.: em abril, +5% para todos).
 */
class LoteController extends Controller
{
    public function index(Request $request): View
    {
        $empresa = $this->empresa($request);
        $competencia = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('competencia')) ? $request->query('competencia') : now()->format('Y-m');

        $contratos = $empresa->nfseContratos()->where('ativo', true)->with(['tomador', 'servico'])->get()
            ->sortBy(fn ($c) => mb_strtolower((string) $c->tomador?->razao_social))->values();

        // Notas que já existem para estes clientes nesta competência
        $existentes = $empresa->nfses()->whereIn('contrato_id', $contratos->pluck('id'))
            ->whereDate('competencia', $competencia.'-01')
            ->whereIn('status', [Nfse::AUTORIZADA, Nfse::PROCESSANDO])
            ->get(['id', 'contrato_id', 'status', 'numero_nfse'])->keyBy('contrato_id');

        return view('empresa.nfse.lote', [
            'empresa' => $empresa,
            'competencia' => $competencia,
            'contratos' => $contratos,
            'existentes' => $existentes,
            'pendencias' => $empresa->pendenciasNfse(),
            'saldo' => $empresa->saldoCreditos(),
        ]);
    }

    public function emitir(Request $request, SalvarNfse $salvar, ValidadorNfse $validador, EmissorNfse $emissor): RedirectResponse
    {
        $empresa = $this->empresa($request);
        $dados = $request->validate([
            'competencia' => ['required', 'date_format:Y-m'],
            'itens' => ['required', 'array'],
            'itens.*.marcado' => ['nullable'],
            'itens.*.valor' => ['nullable', 'string', 'max:20'],
        ]);

        $marcados = collect($dados['itens'])->filter(fn ($i) => ! empty($i['marcado']));
        if ($marcados->isEmpty()) {
            return back()->with('erro', 'Marque pelo menos um cliente.');
        }

        set_time_limit(0); // sem fila (QUEUE_CONNECTION=sync) as notas saem nesta mesma requisição
        $lote = (string) Str::uuid();
        $contratos = $empresa->nfseContratos()->with('servico')->whereIn('id', $marcados->keys())->get()->keyBy('id');
        $enviadas = 0;

        foreach ($marcados as $id => $item) {
            /** @var NfseContrato|null $contrato */
            $contrato = $contratos->get($id);
            if (! $contrato || ! $contrato->servico || $contrato->servico->trashed()) {
                continue;
            }

            $jaExiste = $empresa->nfses()->where('contrato_id', $contrato->id)->whereDate('competencia', $dados['competencia'].'-01')
                ->whereIn('status', [Nfse::AUTORIZADA, Nfse::PROCESSANDO])->exists();
            if ($jaExiste) {
                continue; // nunca emite duas vezes o mesmo cliente no mesmo mês
            }

            // Tentativas anteriores que nem chegaram a ser enviadas saem da lista
            $empresa->nfses()->where('contrato_id', $contrato->id)->whereDate('competencia', $dados['competencia'].'-01')
                ->where('status', Nfse::REJEITADA)->whereNull('numero_dps')->delete();

            $valor = (float) (NumeroBr::paraBanco($item['valor'] ?? null) ?? $contrato->valor);
            $retencoes = collect($contrato->retencoes ?? [])->map(fn ($pct) => round($valor * (float) $pct / 100, 2))->all();

            $nfse = $salvar->salvar($empresa, $request->user(), [
                'servico_id' => $contrato->servico_id,
                'destinatario_id' => $contrato->destinatario_id,
                'competencia' => $dados['competencia'],
                'discriminacao' => $contrato->discriminacaoFinal(),
                'valor_servico' => $valor,
                'iss_retido' => $contrato->iss_retido,
                'contrato_id' => $contrato->id,
                'lote' => $lote,
            ] + $retencoes);

            $problemas = $validador->problemas($nfse->load('empresa'));
            if ($problemas) {
                $nfse->update(['status' => Nfse::REJEITADA, 'motivo' => 'Não enviada: '.implode(' ', $problemas)]);

                continue;
            }

            try {
                $nfse = $emissor->preparar($nfse);
            } catch (\RuntimeException $e) {
                $nfse->update(['status' => Nfse::REJEITADA, 'motivo' => 'Não enviada: '.$e->getMessage()]);

                continue;
            }

            TransmitirNfse::dispatch($nfse);
            $enviadas++;
        }

        return redirect()->route('empresa.nfse.index', ['lote' => $lote])
            ->with('sucesso', $enviadas ? "{$enviadas} NFS-e enviada(s) para a SEFIN Nacional." : null)
            ->with('aviso', $enviadas ? null : 'Nenhuma nota foi enviada. Veja os motivos abaixo.');
    }

    /** Reajuste anual: aplica um percentual sobre o valor dos clientes marcados. */
    public function reajustar(Request $request): RedirectResponse
    {
        $request->merge(['percentual' => NumeroBr::paraBanco($request->input('percentual'))]);
        $dados = $request->validate([
            'percentual' => ['required', 'numeric', 'between:-50,100', 'not_in:0'],
            'contratos' => ['required', 'array', 'min:1'],
            'contratos.*' => ['integer'],
        ], [
            'contratos.required' => 'Marque os clientes que terão o reajuste.',
            'percentual.required' => 'Informe o percentual do reajuste.',
        ]);

        $contratos = $this->empresa($request)->nfseContratos()->whereIn('id', $dados['contratos'])->get();
        foreach ($contratos as $contrato) {
            $contrato->update([
                'valor_anterior' => $contrato->valor,
                'valor' => round((float) $contrato->valor * (1 + (float) $dados['percentual'] / 100), 2),
                'reajustado_em' => now()->toDateString(),
            ]);
        }

        $pct = number_format((float) $dados['percentual'], 2, ',', '.');

        return back()->with('sucesso', "Reajuste de {$pct}% aplicado em {$contratos->count()} cliente(s). Se errou, use \"Desfazer último reajuste\".");
    }

    public function desfazerReajuste(Request $request): RedirectResponse
    {
        $contratos = $this->empresa($request)->nfseContratos()->whereNotNull('valor_anterior')
            ->when($request->input('contratos'), fn ($q, $ids) => $q->whereIn('id', (array) $ids))->get();

        foreach ($contratos as $contrato) {
            $contrato->update(['valor' => $contrato->valor_anterior, 'valor_anterior' => null, 'reajustado_em' => null]);
        }

        return back()->with('sucesso', $contratos->count() ? "Valores anteriores restaurados em {$contratos->count()} cliente(s)." : 'Não há reajuste para desfazer.');
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }
}
