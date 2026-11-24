@extends('layouts.app')

@php
    use App\Models\Nfse;
    $editando = $contrato->exists;
    $ret = $contrato->retencoes ?? [];
    $pct = fn ($c) => isset($ret[$c]) ? str_replace('.', ',', rtrim(rtrim(number_format((float) $ret[$c], 2, '.', ''), '0'), '.')) : '';
    $textos = $servicos->mapWithKeys(fn ($s) => [$s->id => $s->discriminacao]);
@endphp

@section('titulo', $editando ? 'Editar cliente mensal' : 'Novo cliente mensal')

@section('conteudo')
    <div class="max-w-3xl">
        <a href="{{ route('empresa.nfse.clientes.index') }}" class="text-sm text-slate-500 hover:text-marca-700">← Clientes mensais</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $editando ? ($contrato->tomador?->razao_social ?? 'Cliente mensal') : 'Novo cliente mensal' }}</h1>

        @if ($servicos->isEmpty())
            <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                Cadastre primeiro o serviço em <a href="{{ route('empresa.servicos.create') }}" class="underline">Cadastro de serviços</a>.
            </div>
        @else
        <form method="POST" action="{{ $editando ? route('empresa.nfse.clientes.update', $contrato->id) : route('empresa.nfse.clientes.store') }}" class="mt-6 space-y-6">
            @csrf
            @if ($editando) @method('PUT') @endif

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <x-selecao label="Cliente (tomador)" name="destinatario_id" required>
                            <option value="">Escolha...</option>
                            @foreach ($tomadores as $t)
                                <option value="{{ $t->id }}" @selected((int) old('destinatario_id', $contrato->destinatario_id) === $t->id)>{{ $t->razao_social }} - {{ $t->documentoFormatado() }}</option>
                            @endforeach
                        </x-selecao>
                        <p class="mt-1 text-xs text-slate-500">Não está na lista? <a href="{{ route('empresa.pessoas.create') }}" target="_blank" class="text-marca-700 hover:underline">Cadastre a pessoa</a> e recarregue esta página.</p>
                    </div>
                    <div class="sm:col-span-4">
                        <x-selecao label="Serviço" name="servico_id" required>
                            <option value="">Escolha...</option>
                            @foreach ($servicos as $s)
                                <option value="{{ $s->id }}" @selected((int) old('servico_id', $contrato->servico_id) === $s->id)>{{ $s->nome }}</option>
                            @endforeach
                        </x-selecao>
                    </div>
                    <div class="sm:col-span-2">
                        <x-campo label="Valor mensal (R$)" name="valor" :value="$contrato->valor !== null ? number_format((float) $contrato->valor, 2, ',', '.') : ''" required
                                 inputmode="numeric" placeholder="0,00" class="text-right tabular-nums" data-dinheiro />
                    </div>
                    <div class="sm:col-span-6">
                        <label for="discriminacao" class="block text-sm font-medium text-slate-700">Descrição na nota (opcional)</label>
                        <textarea id="discriminacao" name="discriminacao" rows="4" maxlength="2000"
                                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">{{ old('discriminacao', $contrato->discriminacao) }}</textarea>
                        <p class="mt-1 text-xs text-slate-500">Em branco = usa o texto padrão do serviço: <span id="texto-padrao" class="italic"></span>
                            <br><span class="font-mono">{mes}</span> vira o mês da competência (ex.: "Mensalidade de {mes}").</p>
                    </div>
                </div>

                <label class="mt-4 flex items-start gap-2 text-sm text-slate-700 {{ $empresa->ehMei() ? 'opacity-50' : '' }}">
                    <input type="checkbox" name="iss_retido" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('iss_retido', $contrato->iss_retido)) @disabled($empresa->ehMei())>
                    <span>Este cliente retém o ISS</span>
                </label>

                @unless ($empresa->ehMei())
                    <details class="mt-4 rounded-xl border border-slate-200 p-4" @if ($ret) open @endif>
                        <summary class="cursor-pointer text-sm font-medium text-slate-700">Retenções federais deste cliente (em %)</summary>
                        <p class="mt-2 text-xs text-slate-500">Em percentual sobre o valor, para acompanhar os reajustes. Ex.: INSS 11. Simples Nacional normalmente só tem retenção de INSS (cessão de mão de obra).</p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-5">
                            @foreach (Nfse::RETENCOES as $campo => $rotulo)
                                <x-campo :label="$rotulo.' (%)'" :name="'pct_'.$campo" :value="$pct($campo)" inputmode="decimal" placeholder="0" class="text-right" />
                            @endforeach
                        </div>
                    </details>
                @endunless

                <label class="mt-4 flex items-start gap-2 text-sm text-slate-700">
                    <input type="hidden" name="ativo" value="0">
                    <input type="checkbox" name="ativo" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('ativo', $contrato->ativo ?? true))>
                    <span>Ativo <span class="block text-xs text-slate-500">Desmarque para pausar (ex.: cliente de férias) sem perder o cadastro.</span></span>
                </label>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    @if ($editando)
                        <button type="submit" form="excluir" class="text-sm text-red-700 hover:underline">Remover cliente mensal</button>
                    @endif
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('empresa.nfse.clientes.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700 hover:bg-slate-100">Cancelar</a>
                    <button type="submit" class="rounded-lg bg-marca-600 px-5 py-2.5 font-medium text-white hover:bg-marca-700">Salvar</button>
                </div>
            </div>
        </form>

        @if ($editando)
            <form id="excluir" method="POST" action="{{ route('empresa.nfse.clientes.destroy', $contrato->id) }}" onsubmit="return confirm('Remover este cliente da emissão mensal?')">
                @csrf
                @method('DELETE')
            </form>
        @endif
        @endif
    </div>

    @include('partials.dinheiro-js')
    <script>
        (() => {
            const textos = @json($textos);
            const servico = document.getElementById('servico_id');
            const alvo = document.getElementById('texto-padrao');
            if (!servico) return;
            const mostrar = () => { alvo.textContent = textos[servico.value] ? '"' + textos[servico.value] + '"' : '(escolha o serviço)'; };
            servico.addEventListener('change', mostrar);
            mostrar();
        })();
    </script>
@endsection
