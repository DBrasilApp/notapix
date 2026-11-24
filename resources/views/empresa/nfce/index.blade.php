@extends('layouts.app')

@php use App\Support\Formata; @endphp

@section('titulo', 'NFC-e')

@section('conteudo')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">NFC-e (cupons)</h1>
            <p class="mt-1 text-sm text-slate-500">Cupons fiscais emitidos na venda rápida. <a href="{{ route('empresa.notas.index') }}" class="text-marca-700 underline">Ver NF-e</a></p>
        </div>
        <a href="{{ route('empresa.nfce.create') }}" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Venda rápida</a>
    </div>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if ($notas->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-slate-500">Nenhum cupom ainda.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr><th class="px-5 py-2 font-medium">Número</th><th class="px-5 py-2 font-medium">Data</th><th class="px-5 py-2 font-medium">Consumidor</th><th class="px-5 py-2 text-right font-medium">Total</th><th class="px-5 py-2 font-medium">Situação</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($notas as $nota)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-2"><a href="{{ route('empresa.nfce.show', $nota->id) }}" class="font-medium text-marca-700 hover:underline">{{ $nota->numero ?? 'Rascunho' }}</a></td>
                                <td class="px-5 py-2 text-slate-600">{{ ($nota->autorizada_em ?? $nota->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-2 text-slate-600">{{ $nota->nomeDestinatario() }}</td>
                                <td class="px-5 py-2 text-right tabular-nums">{{ Formata::moeda($nota->valor_total) }}</td>
                                <td class="px-5 py-2"><x-status-nota :nota="$nota" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3">{{ $notas->links() }}</div>
        @endif
    </section>
@endsection
