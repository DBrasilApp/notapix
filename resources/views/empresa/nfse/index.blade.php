@extends('layouts.app')

@php
    use App\Models\Nfse;
    use App\Support\Formata;
@endphp

@section('titulo', 'NFS-e emitidas')

@if ($processando)
    @push('head')<meta http-equiv="refresh" content="4">@endpush
@endif

@section('conteudo')
    @include('empresa.nfse.partials.abas', ['aba' => 'emitidas'])

    <div class="mt-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ $lote ? 'Resultado da emissão em lote' : 'NFS-e emitidas' }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if ($lote)
                    @if ($processando) Enviando... esta página se atualiza sozinha. @else Pronto. Confira abaixo a situação de cada nota. @endif
                    <a href="{{ route('empresa.nfse.index') }}" class="ml-1 text-marca-700 hover:underline">Ver todas as NFS-e</a>
                @else
                    Notas fiscais de serviço (padrão nacional).
                @endif
            </p>
        </div>
        <a href="{{ route('empresa.nfse.create') }}" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Emitir NFS-e</a>
    </div>

    @unless ($lote)
        <form method="GET" class="mt-6 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-500" for="competencia">Competência</label>
                <input type="month" id="competencia" name="competencia" value="{{ $competencia }}"
                       class="mt-1 rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500" for="situacao">Situação</label>
                <select id="situacao" name="situacao" class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 shadow-sm">
                    <option value="">Todas</option>
                    @foreach (Nfse::STATUS as $chave => $rotulo)
                        <option value="{{ $chave }}" @selected(request('situacao') === $chave)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
            <button class="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-100">Filtrar</button>
        </form>
    @endunless

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if ($notas->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-500">Nenhuma NFS-e encontrada.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Nº</th>
                            <th class="px-5 py-2 font-medium">Competência</th>
                            <th class="px-5 py-2 font-medium">Tomador</th>
                            <th class="px-5 py-2 text-right font-medium">Valor</th>
                            <th class="px-5 py-2 font-medium">Situação</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($notas as $n)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3 tabular-nums">
                                    <a href="{{ route('empresa.nfse.show', $n->id) }}" class="font-medium hover:text-marca-700">{{ $n->numero_nfse ?? '—' }}</a>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ $n->competenciaTexto() }}</td>
                                <td class="px-5 py-3">
                                    <a href="{{ route('empresa.nfse.show', $n->id) }}" class="hover:text-marca-700">{{ $n->tomadorNome() }}</a>
                                    @if ($n->status === Nfse::REJEITADA)
                                        <span class="block max-w-md truncate text-xs text-red-700" title="{{ $n->motivo }}">{{ $n->motivo }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">{{ Formata::moeda($n->valor_servico) }}</td>
                                <td class="px-5 py-3"><x-status-nota :nota="$n" /></td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    @if (in_array($n->status, [Nfse::AUTORIZADA, Nfse::CANCELADA], true))
                                        <a href="{{ route('empresa.nfse.pdf', $n->id) }}" target="_blank" class="text-marca-700 hover:underline">PDF</a>
                                    @elseif ($n->status === Nfse::REJEITADA)
                                        <a href="{{ route('empresa.nfse.edit', $n->id) }}" class="text-red-700 hover:underline">Corrigir</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mt-4">{{ $notas->links() }}</div>
@endsection
