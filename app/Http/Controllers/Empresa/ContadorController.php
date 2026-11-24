<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\Escritorio;
use App\Rules\Cnpj;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** A empresa autoriza (ou deixa de autorizar) o escritório contábil a trabalhar nela. */
class ContadorController extends Controller
{
    public function show(Request $request): View
    {
        $empresa = $request->user()->empresa;

        return view('empresa.contador', [
            'empresa' => $empresa,
            'vinculo' => $empresa->escritorios()->wherePivotIn('status', ['ativo', 'pendente'])->latest('escritorio_empresa.updated_at')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        $request->merge(['cnpj_escritorio' => Cnpj::somenteNumeros((string) $request->input('cnpj_escritorio'))]);
        $request->validate(
            ['cnpj_escritorio' => ['required', 'digits:14', new Cnpj]],
            ['cnpj_escritorio.required' => 'Informe o CNPJ do escritório contábil.'],
            ['cnpj_escritorio' => 'CNPJ do escritório'],
        );

        if ($empresa->escritorios()->wherePivotIn('status', ['ativo', 'pendente'])->exists()) {
            return back()->with('erro', 'A empresa já tem um escritório contábil. Remova o atual antes de autorizar outro.');
        }

        $escritorio = Escritorio::where('cnpj', $request->input('cnpj_escritorio'))->where('ativo', true)->first();
        if (! $escritorio) {
            return back()->withInput()->withErrors(['cnpj_escritorio' => 'Este escritório ainda não usa o NotaPix. Peça para ele criar a conta em "Criar conta → Sou escritório contábil".']);
        }

        // Pedido pela empresa: fica pendente até o escritório aceitar
        $empresa->escritorios()->syncWithoutDetaching([$escritorio->id => [
            'status' => 'pendente', 'solicitado_por' => 'empresa', 'autorizado_em' => now(), 'revogado_em' => null,
        ]]);
        Auditoria::registrar('contador_autorizado', $empresa, ['escritorio_id' => $escritorio->id]);

        return redirect()->route('empresa.contador')
            ->with('sucesso', "Pedido enviado. Quando {$escritorio->razao_social} aceitar, o escritório poderá trabalhar na sua empresa.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        $vinculo = $empresa->escritorios()->wherePivotIn('status', ['ativo', 'pendente'])->first();

        if ($vinculo) {
            $empresa->escritorios()->updateExistingPivot($vinculo->id, [
                'status' => 'revogado', 'revogado_em' => now(), 'usar_saldo_escritorio' => false,
            ]);
            Auditoria::registrar('contador_removido', $empresa, ['escritorio_id' => $vinculo->id]);
        }

        return redirect()->route('empresa.contador')->with('sucesso', 'O escritório não tem mais acesso à sua empresa.');
    }
}
