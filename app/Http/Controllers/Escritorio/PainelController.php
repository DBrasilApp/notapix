<?php

namespace App\Http\Controllers\Escritorio;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Nota;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PainelController extends Controller
{
    public function __invoke(Request $request): View
    {
        $escritorio = $request->user()->escritorio;
        abort_unless($escritorio, 403, 'Seu usuário não está ligado a um escritório.');

        $empresas = $escritorio->empresasAtivas()
            ->with('certificadoAtivo')
            ->withSum('creditosMovimentos as saldo', 'quantidade')
            ->withCount([
                'notas as notas_mes' => fn ($q) => $q->where('status', Nota::AUTORIZADA)->where('autorizada_em', '>=', now()->startOfMonth()),
                'notas as rejeitadas' => fn ($q) => $q->where('status', Nota::REJEITADA),
            ])
            ->orderBy('razao_social')
            ->get();

        // Alertas de cada empresa, em linguagem simples
        $alertas = $empresas->mapWithKeys(function (Empresa $e) {
            $lista = [];
            $dias = $e->certificadoAtivo?->venceEmDias();
            if (! $e->certificadoAtivo) {
                $lista[] = 'Sem certificado digital';
            } elseif ($dias !== null && $dias < 0) {
                $lista[] = 'Certificado vencido';
            } elseif ($dias !== null && $dias <= 30) {
                $lista[] = "Certificado vence em {$dias} dia(s)";
            }
            if (! $e->dadosCadastraisCompletos()) {
                $lista[] = 'Cadastro incompleto';
            }
            if ($e->rejeitadas > 0) {
                $lista[] = $e->rejeitadas.' nota(s) rejeitada(s) para corrigir';
            }

            return [$e->id => $lista];
        });

        return view('escritorio.painel', [
            'escritorio' => $escritorio,
            'saldo' => $escritorio->saldoCreditos(),
            'empresas' => $empresas,
            'alertas' => $alertas,
            'pedidos' => $escritorio->empresas()->wherePivot('status', 'pendente')->orderBy('razao_social')->get(),
        ]);
    }
}
