@extends('layouts.app')

@section('titulo', 'XML do mês')

@section('conteudo')
    <div class="max-w-2xl">
        <a href="{{ route('escritorio.painel') }}" class="text-sm text-slate-500 hover:text-marca-700">← Painel do escritório</a>
        <h1 class="mt-2 text-2xl font-semibold">XML do mês</h1>
        <p class="mt-1 text-sm text-slate-600">
            Baixa um arquivo .zip com os XMLs e DANFEs das notas autorizadas, canceladas e denegadas no mês,
            junto com os XMLs de cancelamento e das cartas de correção. Com "todas as empresas", cada uma fica numa pasta.
        </p>

        <form method="GET" action="{{ route('escritorio.arquivos') }}" class="mt-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-3">
            <label class="text-sm font-medium text-slate-700 sm:col-span-2">Empresa
                <select name="empresa" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                    <option value="">Todas as empresas</option>
                    @foreach ($empresas as $empresa)
                        <option value="{{ $empresa->id }}">{{ $empresa->razao_social }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700">Mês
                <input type="month" name="mes" value="{{ now()->subMonthNoOverflow()->format('Y-m') }}" max="{{ now()->format('Y-m') }}" required
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal">
            </label>
            <div class="sm:col-span-3">
                <button class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700" @disabled($empresas->isEmpty())>Baixar .zip</button>
            </div>
        </form>
    </div>
@endsection
