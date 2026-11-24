<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PainelController extends Controller
{
    public function __invoke(Request $request): View
    {
        $empresa = $request->user()->empresa;
        $empresa->load('certificadoAtivo');

        return view('empresa.painel', [
            'empresa' => $empresa,
            'saldo' => $empresa->saldoCreditos(),
            'certificado' => $empresa->certificadoAtivo,
            'pendencias' => $empresa->pendenciasParaEmitir(),
            'notasNoMes' => $empresa->notas()
                ->where('status', 'autorizada')
                ->where('autorizada_em', '>=', now()->startOfMonth())
                ->count(),
        ]);
    }
}
