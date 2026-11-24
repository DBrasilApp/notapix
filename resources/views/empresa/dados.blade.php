@extends('layouts.app')

@section('titulo', 'Dados da empresa')

@section('conteudo')
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold">Dados da empresa</h1>

        <form method="POST" action="{{ route('empresa.dados.update') }}" class="mt-6 space-y-6">
            @csrf
            @method('PUT')

            @include('empresa.partials.formulario-dados', [
                'empresa' => $empresa, 'ufs' => $ufs, 'modo' => 'edicao',
                'urlCnpj' => route('empresa.cnpj.mostrar', '00000000000000'),
            ])

            <div class="flex justify-end gap-3">
                <a href="{{ route('empresa.painel') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700 hover:bg-slate-100">Voltar</a>
                <button type="submit" class="rounded-lg bg-marca-600 px-5 py-2.5 font-medium text-white hover:bg-marca-700">Salvar dados</button>
            </div>
        </form>
    </div>
@endsection
