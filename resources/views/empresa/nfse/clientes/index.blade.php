@extends('layouts.app')

@php use App\Support\Formata; @endphp

@section('titulo', 'Clientes mensais')

@section('conteudo')
    @include('empresa.nfse.partials.abas', ['aba' => 'clientes'])

    <div class="mt-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Clientes mensais</h1>
            <p class="mt-1 text-sm text-slate-500">Quem recebe a mesma NFS-e todo mês. Cadastre uma vez e emita todas juntas em <a href="{{ route('empresa.nfse.lote') }}" class="text-marca-700 hover:underline">Emissão em lote</a>.</p>
        </div>
        <a href="{{ route('empresa.nfse.clientes.create') }}" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Adicionar cliente</a>
    </div>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if ($contratos->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-500">Nenhum cliente mensal ainda. Comece adicionando os clientes que você atende todo mês.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Cliente</th>
                            <th class="px-5 py-2 font-medium">Serviço</th>
                            <th class="px-5 py-2 text-right font-medium">Valor mensal</th>
                            <th class="px-5 py-2 font-medium">Último reajuste</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($contratos as $c)
                            <tr class="{{ $c->ativo ? '' : 'opacity-50' }}">
                                <td class="px-5 py-3">
                                    <a href="{{ route('empresa.nfse.clientes.edit', $c->id) }}" class="font-medium hover:text-marca-700">{{ $c->tomador?->razao_social }}</a>
                                    @unless ($c->ativo)<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">pausado</span>@endunless
                                    @if ($c->iss_retido)<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">ISS retido</span>@endif
                                    <span class="block text-xs text-slate-500">{{ $c->tomador?->documentoFormatado() }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $c->servico?->nome }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">{{ Formata::moeda($c->valor) }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600">
                                    @if ($c->reajustado_em)
                                        {{ $c->reajustado_em->format('d/m/Y') }}
                                        <span class="block text-xs text-slate-500">antes: {{ Formata::moeda($c->valor_anterior) }}</span>
                                    @else - @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <a href="{{ route('empresa.nfse.create', ['servico' => $c->servico_id, 'tomador' => $c->destinatario_id]) }}" class="text-marca-700 hover:underline">Emitir avulsa</a>
                                    <span class="text-slate-300">|</span>
                                    <a href="{{ route('empresa.nfse.clientes.edit', $c->id) }}" class="text-marca-700 hover:underline">Editar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
