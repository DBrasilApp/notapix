@extends('layouts.app')

@section('titulo', 'Pessoas')

@section('conteudo')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Pessoas</h1>
            <p class="mt-1 text-sm text-slate-500">Clientes e demais destinatários das suas notas, pessoa física ou jurídica.</p>
        </div>
        <a href="{{ route('empresa.pessoas.create') }}" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Nova pessoa</a>
    </div>

    <form method="GET" class="mt-6 flex gap-2">
        <input type="search" name="busca" value="{{ $busca }}" placeholder="Buscar por nome, fantasia, CPF ou CNPJ"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">
        <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-100">Buscar</button>
    </form>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if ($pessoas->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-slate-500">
                {{ $busca ? 'Nenhuma pessoa encontrada para esta busca.' : 'Nenhuma pessoa cadastrada ainda.' }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Nome</th>
                            <th class="px-5 py-2 font-medium">CPF / CNPJ</th>
                            <th class="px-5 py-2 font-medium">Cidade</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($pessoas as $pessoa)
                            <tr>
                                <td class="px-5 py-3">
                                    <a href="{{ route('empresa.pessoas.edit', $pessoa->id) }}" class="font-medium hover:text-marca-700">{{ $pessoa->razao_social }}</a>
                                    @if ($pessoa->nome_fantasia)
                                        <div class="text-xs text-slate-500">{{ $pessoa->nome_fantasia }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    {{ $pessoa->documentoFormatado() }}
                                    <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ $pessoa->isFisica() ? 'PF' : 'PJ' }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $pessoa->municipio }}/{{ $pessoa->uf }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('empresa.pessoas.edit', $pessoa->id) }}" class="text-marca-700 hover:underline">Editar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mt-4">{{ $pessoas->links() }}</div>
@endsection
