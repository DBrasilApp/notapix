@extends('layouts.app')

@php use App\Support\Formata; @endphp

@section('titulo', 'Painel do escritório')

@section('conteudo')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ $escritorio->razao_social }}</h1>
            <p class="mt-1 text-sm text-slate-500">CNPJ {{ Formata::cnpj($escritorio->cnpj) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('escritorio.arquivos') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700 hover:bg-slate-100">XML do mês</a>
            <a href="{{ route('escritorio.empresas.create') }}" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Adicionar empresa</a>
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <x-cartao titulo="Créditos do escritório" :valor="$saldo" detalhe="Repasse para as empresas ou deixe que usem quando os delas acabarem." />
        <x-cartao titulo="Empresas-clientes" :valor="$empresas->count()" />
        <x-cartao titulo="Notas autorizadas no mês" :valor="$empresas->sum('notas_mes')" detalhe="Somando todas as empresas." />
    </div>

    {{-- Pedidos de empresas que autorizaram o escritório --}}
    @if ($pedidos->isNotEmpty())
        <section class="mt-8 rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-medium text-amber-900">Empresas pedindo para você ser o contador delas</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($pedidos as $pedido)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-4 py-3 text-sm">
                        <div>
                            <div class="font-medium">{{ $pedido->razao_social }}</div>
                            <div class="text-xs text-slate-500">CNPJ {{ Formata::cnpj($pedido->cnpj) }}</div>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('escritorio.empresas.aceitar', $pedido->id) }}">@csrf
                                <button class="rounded-lg bg-marca-600 px-3 py-1.5 font-medium text-white hover:bg-marca-700">Aceitar</button>
                            </form>
                            <form method="POST" action="{{ route('escritorio.empresas.desvincular', $pedido->id) }}">@csrf @method('DELETE')
                                <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-100">Recusar</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
        <h2 class="border-b border-slate-200 px-5 py-3 font-medium">Empresas-clientes</h2>

        @forelse ($empresas as $empresa)
            <div class="border-b border-slate-100 px-5 py-4 text-sm last:border-0">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="font-medium">{{ $empresa->razao_social }}</div>
                        <div class="text-xs text-slate-500">CNPJ {{ Formata::cnpj($empresa->cnpj) }} · {{ $empresa->municipio }}/{{ $empresa->uf }}</div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                            <span>Saldo: <strong>{{ (int) $empresa->saldo }}</strong> crédito(s)</span>
                            <span>Notas no mês: <strong>{{ $empresa->notas_mes }}</strong></span>
                            @if ($empresa->emHomologacao())<span class="text-amber-700">Homologação</span>@endif
                        </div>
                        @if ($alertas[$empresa->id])
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($alertas[$empresa->id] as $alerta)
                                    <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700 ring-1 ring-red-100">{{ $alerta }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('escritorio.empresas.acessar', $empresa->id) }}">@csrf
                        <button class="rounded-lg bg-marca-600 px-4 py-2 font-medium text-white hover:bg-marca-700">Acessar</button>
                    </form>
                </div>

                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-marca-700">Créditos e vínculo</summary>
                    <div class="mt-3 grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">
                        <form method="POST" action="{{ route('escritorio.empresas.creditos', $empresa->id) }}" class="flex items-end gap-2">
                            @csrf
                            <label class="text-xs text-slate-600">Repassar créditos do escritório
                                <input type="number" name="quantidade" min="1" max="{{ max($saldo, 1) }}" required
                                       class="mt-1 block w-28 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            </label>
                            <button class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100" @disabled($saldo < 1)>Repassar</button>
                        </form>

                        <form method="POST" action="{{ route('escritorio.empresas.saldo', $empresa->id) }}" class="text-xs text-slate-600">
                            @csrf
                            <input type="hidden" name="ligar" value="{{ $empresa->pivot->usar_saldo_escritorio ? 0 : 1 }}">
                            <p>{{ $empresa->pivot->usar_saldo_escritorio ? 'Quando os créditos da empresa acabam, ela usa os do escritório.' : 'A empresa usa só os próprios créditos.' }}</p>
                            <button class="mt-1 text-marca-700 underline">
                                {{ $empresa->pivot->usar_saldo_escritorio ? 'Desligar uso dos créditos do escritório' : 'Deixar usar os créditos do escritório quando acabarem os dela' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('escritorio.empresas.desvincular', $empresa->id) }}" class="sm:col-span-2"
                              onsubmit="return confirm('Deixar de atender {{ addslashes($empresa->razao_social) }}? A empresa continua no NotaPix, só o escritório perde o acesso.')">
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-700 underline">Deixar de atender esta empresa</button>
                        </form>
                    </div>
                </details>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm text-slate-500">
                <p>Nenhuma empresa-cliente ainda.</p>
                <p class="mt-1">Clique em <strong>Adicionar empresa</strong> para cadastrar um cliente, ou peça para a empresa que já usa o NotaPix autorizar o seu escritório em <strong>Contador</strong>.</p>
            </div>
        @endforelse
    </section>
@endsection
