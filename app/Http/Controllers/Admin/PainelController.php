<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Escritorio;
use App\Models\Nota;
use App\Models\User;
use Illuminate\View\View;

class PainelController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.painel', [
            'totalEmpresas' => Empresa::count(),
            'totalEscritorios' => Escritorio::count(),
            'totalUsuarios' => User::where('perfil', '!=', Perfil::Admin->value)->count(),
            'notasAutorizadas' => Nota::where('status', 'autorizada')->count(),
            'ultimosCadastros' => User::with(['empresa', 'escritorio'])
                ->where('perfil', '!=', Perfil::Admin->value)
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }
}
