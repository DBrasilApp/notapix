@extends('layouts.app')

@php
    use App\Support\TabelaServicosNacional;
    $editando = $servico->exists;
    $codigoAtual = old('ctribnac', $servico->ctribnac);
@endphp

@section('titulo', $editando ? 'Editar serviço' : 'Novo serviço')

@section('conteudo')
    <div class="max-w-3xl">
        <a href="{{ route('empresa.servicos.index') }}" class="text-sm text-slate-500 hover:text-marca-700">← Serviços</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $editando ? $servico->nome : 'Novo serviço' }}</h1>

        <form method="POST" action="{{ $editando ? route('empresa.servicos.update', $servico->id) : route('empresa.servicos.store') }}" class="mt-6 space-y-6">
            @csrf
            @if ($editando) @method('PUT') @endif
            <input type="hidden" name="voltar" value="{{ request('voltar') }}">

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-medium">Serviço</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-4">
                        <x-campo label="Nome do serviço" name="nome" :value="$servico->nome" required maxlength="120"
                                 ajuda="Só para você encontrar o serviço. Ex.: Manutenção de computadores." />
                    </div>
                    <div class="sm:col-span-2">
                        <x-campo label="Valor padrão (R$, opcional)" name="valor" :value="$servico->valor !== null ? number_format((float) $servico->valor, 2, ',', '.') : ''"
                                 inputmode="numeric" placeholder="0,00" class="text-right tabular-nums" data-dinheiro />
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-medium">Código do serviço</h2>
                <div class="mt-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                    <p>Use o <strong>mesmo código</strong> das notas que você já emite no portal da NFS-e Nacional. Ele aparece no formato
                        <span class="font-mono">01.07.01.001</span>: os 6 primeiros números são o código da <strong>lista nacional</strong>
                        (<span class="font-mono">01.07.01</span>) e os 3 últimos, o <strong>código municipal</strong> (<span class="font-mono">001</span>).</p>
                    <p class="mt-1">Se a sua prefeitura não usa código municipal, deixe-o em branco.</p>
                </div>

                <div class="mt-4">
                    @include('partials.busca-tabela', [
                        'nome' => 'ctribnac', 'rotulo' => 'Código da lista nacional', 'valor' => TabelaServicosNacional::formatar($codigoAtual),
                        'descricaoAtual' => $codigoAtual ? (TabelaServicosNacional::paraJson(TabelaServicosNacional::limpar($codigoAtual))['descricao_completa'] ?? null) : null,
                        'digitos' => 6, 'mascara' => 'servico', 'placeholder' => '00.00.00',
                        'urlBusca' => route('empresa.servicos.codigos.buscar'), 'urlCodigo' => url('/empresa/servicos/codigos'),
                        'textoBusca' => 'Ex.: informática, contabilidade, limpeza, consultoria',
                        'ajudaBusca' => 'Lista nacional de serviços da NFS-e (LC 116/2003).',
                        'campoExtra' => ['label' => 'Código municipal (opcional)', 'name' => 'ctribmun', 'value' => $servico->ctribmun, 'maxlength' => 3,
                                         'ajuda' => '3 números. Ex.: 001'],
                    ])
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-3">
                        <x-campo label="Código NBS (opcional)" name="cnbs" :value="$servico->cnbs" inputmode="numeric" maxlength="9"
                                 ajuda="9 números. Aparece nas notas do portal como 'NBS'. Pode deixar em branco por enquanto." />
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-medium">Texto da nota</h2>
                <div class="mt-4">
                    <label for="discriminacao" class="block text-sm font-medium text-slate-700">Descrição do serviço (vai na NFS-e)</label>
                    <textarea id="discriminacao" name="discriminacao" rows="5" maxlength="2000" required
                              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">{{ old('discriminacao', $servico->discriminacao) }}</textarea>
                    <p class="mt-1 text-xs text-slate-500">Pode ser mudada em cada nota. Use <span class="font-mono">{mes}</span> para o mês da competência
                        (ex.: "Honorários referentes a {mes}" vira "Honorários referentes a 11/2026").</p>
                    @error('discriminacao')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <label class="mt-4 flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="iss_retido" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('iss_retido', $servico->iss_retido))>
                    <span>Normalmente o tomador <strong>retém o ISS</strong> deste serviço
                        <span class="block text-xs text-slate-500">Só marque se o seu cliente desconta o ISS do pagamento (comum com prefeituras e grandes empresas). Pode mudar em cada nota.</span></span>
                </label>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    @if ($editando)
                        <button type="submit" form="excluir" class="text-sm text-red-700 hover:underline">Excluir serviço</button>
                    @endif
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('empresa.servicos.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700 hover:bg-slate-100">Cancelar</a>
                    <button type="submit" class="rounded-lg bg-marca-600 px-5 py-2.5 font-medium text-white hover:bg-marca-700">Salvar</button>
                </div>
            </div>
        </form>

        @if ($editando)
            <form id="excluir" method="POST" action="{{ route('empresa.servicos.destroy', $servico->id) }}"
                  onsubmit="return confirm('Excluir este serviço? As notas já emitidas não são afetadas.')">
                @csrf
                @method('DELETE')
            </form>
        @endif
    </div>

    @include('partials.dinheiro-js')
    <script>
        ['ctribmun', 'cnbs'].forEach((id) => {
            const el = document.getElementById(id);
            el?.addEventListener('input', () => { el.value = el.value.replace(/\D/g, ''); });
        });
    </script>
@endsection
