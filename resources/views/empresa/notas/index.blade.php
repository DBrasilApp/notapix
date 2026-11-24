@extends('layouts.app')

@section('titulo', 'Notas fiscais')

@section('conteudo')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Notas fiscais</h1>
            <p class="mt-1 text-sm text-slate-500">NF-e modelo 55 emitidas pela empresa. <a href="{{ route('empresa.nfce.index') }}" class="text-marca-700 underline">Ver NFC-e (cupons)</a></p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <form method="GET" action="{{ route('empresa.notas.arquivos') }}" class="flex items-end gap-2">
                <label class="text-xs text-slate-500">XML do mês
                    <input type="month" name="mes" value="{{ now()->subMonthNoOverflow()->format('Y-m') }}" max="{{ now()->format('Y-m') }}"
                           class="mt-1 block rounded-lg border border-slate-300 px-2 py-2 text-sm text-slate-800">
                </label>
                <button class="rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-700 hover:bg-slate-100" title="Baixa um .zip com os XMLs e DANFEs do mês">Baixar .zip</button>
            </form>
            <a href="{{ route('empresa.notas.create') }}" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Nova nota</a>
        </div>
    </div>

    @if ($pendencias)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-medium">Antes de emitir, resolva:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($pendencias as $pendencia)<li>{{ $pendencia }}</li>@endforeach
            </ul>
            <p class="mt-1 text-xs">Você já pode preencher a nota e salvar como rascunho.</p>
        </div>
    @endif

    <nav class="mt-6 flex flex-wrap gap-2 text-sm">
        <a href="{{ route('empresa.notas.index') }}" class="rounded-full px-3 py-1 {{ ! $status ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200' }}">Todas</a>
        @foreach (\App\Models\Nota::STATUS as $chave => $nome)
            <a href="{{ route('empresa.notas.index', ['status' => $chave]) }}" class="rounded-full px-3 py-1 {{ $status === $chave ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200' }}">{{ $nome }}</a>
        @endforeach
    </nav>

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if ($notas->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-slate-500">Nenhuma nota {{ $status ? 'nesta situação' : 'ainda' }}.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Número</th>
                            <th class="px-5 py-2 font-medium">Destinatário</th>
                            <th class="px-5 py-2 font-medium">Data</th>
                            <th class="px-5 py-2 text-right font-medium">Valor</th>
                            <th class="px-5 py-2 font-medium">Situação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($notas as $nota)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <a href="{{ route('empresa.notas.show', $nota->id) }}" class="font-medium hover:text-marca-700">{{ $nota->numeroFormatado() }}</a>
                                    @if ($nota->ambiente === 2)<span class="ml-1 text-xs text-amber-700">homolog.</span>@endif
                                </td>
                                <td class="px-5 py-3">
                                    <a href="{{ route('empresa.notas.show', $nota->id) }}" class="hover:text-marca-700">{{ $nota->nomeDestinatario() }}</a>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ ($nota->data_emissao ?? $nota->created_at)->format('d/m/Y') }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">{{ \App\Support\Formata::moeda($nota->valor_total) }}</td>
                                <td class="px-5 py-3"><x-status-nota :nota="$nota" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mt-4">{{ $notas->links() }}</div>
@endsection
