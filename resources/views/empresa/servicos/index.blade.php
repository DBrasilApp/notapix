@extends('layouts.app')

@section('titulo', 'Serviços')

@section('conteudo')
    @include('empresa.nfse.partials.abas', ['aba' => 'servicos'])

    <div class="mt-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Cadastro de serviços</h1>
            <p class="mt-1 text-sm text-slate-500">Os serviços que você presta, com o código da lista nacional e o texto que vai na NFS-e.</p>
        </div>
        <a href="{{ route('empresa.servicos.create') }}" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Novo serviço</a>
    </div>

    <form method="GET" class="mt-6 flex gap-2">
        <input type="search" name="busca" value="{{ $busca }}" placeholder="Buscar por nome ou código"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">
        <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-100">Buscar</button>
    </form>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if ($servicos->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-slate-500">
                @if ($busca)
                    Nenhum serviço encontrado para esta busca.
                @else
                    <p>Nenhum serviço cadastrado ainda.</p>
                    <p class="mt-1">Dica: abra uma NFS-e que você já emitiu no portal do governo e copie de lá o código do serviço e a descrição.</p>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Código</th>
                            <th class="px-5 py-2 font-medium">Serviço</th>
                            <th class="px-5 py-2 font-medium">Lista nacional</th>
                            <th class="px-5 py-2 text-right font-medium">Valor padrão</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($servicos as $servico)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ $servico->codigo }}</td>
                                <td class="px-5 py-3">
                                    <a href="{{ route('empresa.servicos.edit', $servico->id) }}" class="font-medium hover:text-marca-700">{{ $servico->nome }}</a>
                                    @if ($servico->iss_retido)<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">ISS retido</span>@endif
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    <span class="tabular-nums">{{ \App\Support\TabelaServicosNacional::formatar($servico->ctribnac) }}{{ $servico->ctribmun ? '.'.$servico->ctribmun : '' }}</span>
                                    <span class="block max-w-md truncate text-xs text-slate-500">{{ \App\Support\TabelaServicosNacional::descricao($servico->ctribnac) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">{{ $servico->valor !== null ? \App\Support\Formata::moeda($servico->valor) : '-' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <a href="{{ route('empresa.nfse.create', ['servico' => $servico->id]) }}" class="text-marca-700 hover:underline">Emitir</a>
                                    <span class="text-slate-300">|</span>
                                    <a href="{{ route('empresa.servicos.edit', $servico->id) }}" class="text-marca-700 hover:underline">Editar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mt-4">{{ $servicos->links() }}</div>
@endsection
