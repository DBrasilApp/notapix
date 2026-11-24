@extends('layouts.app')

@section('titulo', 'Produtos')

@section('conteudo')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Produtos</h1>
            <p class="mt-1 text-sm text-slate-500">Produtos que você vende, com a tributação já sugerida para a nota.</p>
        </div>
        <a href="{{ route('empresa.produtos.create') }}" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Novo produto</a>
    </div>

    <form method="GET" class="mt-6 flex gap-2">
        <input type="search" name="busca" value="{{ $busca }}" placeholder="Buscar por descrição, código, código de barras ou NCM"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">
        <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-100">Buscar</button>
    </form>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if ($produtos->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-slate-500">
                {{ $busca ? 'Nenhum produto encontrado para esta busca.' : 'Nenhum produto cadastrado ainda.' }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Código</th>
                            <th class="px-5 py-2 font-medium">Descrição</th>
                            <th class="px-5 py-2 font-medium">NCM</th>
                            <th class="px-5 py-2 font-medium">Unid.</th>
                            <th class="px-5 py-2 text-right font-medium">Preço</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($produtos as $produto)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ $produto->codigo }}</td>
                                <td class="px-5 py-3">
                                    <a href="{{ route('empresa.produtos.edit', $produto->id) }}" class="font-medium hover:text-marca-700">{{ $produto->descricao }}</a>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ \App\Support\Formata::ncm($produto->ncm) }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $produto->unidade }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">{{ $produto->precoFormatado() }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('empresa.produtos.edit', $produto->id) }}" class="text-marca-700 hover:underline">Editar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mt-4">{{ $produtos->links() }}</div>
@endsection
