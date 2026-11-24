@extends('layouts.app')

@php
    use App\Models\Nfse;
    use App\Support\Formata;
    [$ano, $mes] = explode('-', $competencia);
    $nomeMes = \Carbon\Carbon::create((int) $ano, (int) $mes, 1)->locale('pt_BR')->translatedFormat('F \d\e Y');
    $temReajuste = $contratos->contains(fn ($c) => $c->valor_anterior !== null);
@endphp

@section('titulo', 'Emissão em lote')

@section('conteudo')
    @include('empresa.nfse.partials.abas', ['aba' => 'lote'])

    <div class="mt-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Emissão em lote</h1>
            <p class="mt-1 text-sm text-slate-500">Uma NFS-e para cada cliente mensal marcado. Confira os valores e emita todas de uma vez.</p>
        </div>
        <form method="GET" class="flex items-end gap-2">
            <div>
                <label for="competencia" class="block text-xs font-medium text-slate-500">Competência</label>
                <input type="month" id="competencia" name="competencia" value="{{ $competencia }}" max="{{ now()->format('Y-m') }}" onchange="this.form.submit()"
                       class="mt-1 rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:outline-none">
            </div>
        </form>
    </div>

    @if ($pendencias)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-medium">Antes de emitir, falta:</p>
            <ul class="mt-1 list-disc pl-5">@foreach ($pendencias as $texto)<li>{{ $texto }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($contratos->isEmpty())
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <p class="font-medium">Nenhum cliente mensal cadastrado.</p>
            <p class="mt-1 text-sm text-slate-500">Cadastre uma vez quem recebe nota todo mês (cliente, serviço e valor). Depois é só conferir e emitir aqui.</p>
            <a href="{{ route('empresa.nfse.clientes.create') }}" class="mt-4 inline-block rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Adicionar cliente mensal</a>
        </div>
    @else
        <form method="POST" action="{{ route('empresa.nfse.lote.emitir') }}" id="form-lote" class="mt-6"
              onsubmit="return confirm('Emitir as NFS-e marcadas da competência {{ $mes }}/{{ $ano }}?')">
            @csrf
            <input type="hidden" name="competencia" value="{{ $competencia }}">

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-slate-500">
                            <tr>
                                <th class="w-10 px-4 py-2"><input type="checkbox" id="marcar-todos" class="rounded border-slate-300" title="Marcar todos"></th>
                                <th class="px-4 py-2 font-medium">Cliente</th>
                                <th class="px-4 py-2 font-medium">Serviço</th>
                                <th class="px-4 py-2 text-right font-medium">Valor ({{ $mes }}/{{ $ano }})</th>
                                <th class="px-4 py-2 font-medium">Situação no mês</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($contratos as $c)
                                @php($existente = $existentes->get($c->id))
                                <tr class="{{ $existente ? 'bg-slate-50 text-slate-500' : '' }}">
                                    <td class="px-4 py-3">
                                        @if ($existente)
                                            <input type="checkbox" disabled class="rounded border-slate-200">
                                        @else
                                            <input type="checkbox" name="itens[{{ $c->id }}][marcado]" value="1" class="rounded border-slate-300" data-marcar data-id="{{ $c->id }}"
                                                   @checked(old("itens.{$c->id}.marcado", true))>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium">{{ $c->tomador?->razao_social }}</span>
                                        @if ($c->iss_retido)<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">ISS retido</span>@endif
                                        <span class="block text-xs text-slate-500">{{ $c->tomador?->documentoFormatado() }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ $c->servico?->nome }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($existente)
                                            <span class="tabular-nums">{{ Formata::moeda($c->valor) }}</span>
                                        @else
                                            <input type="text" name="itens[{{ $c->id }}][valor]" value="{{ old("itens.{$c->id}.valor", number_format((float) $c->valor, 2, ',', '.')) }}"
                                                   inputmode="numeric" data-dinheiro data-valor
                                                   class="w-32 rounded-lg border border-slate-300 px-2 py-1.5 text-right tabular-nums focus:border-marca-500 focus:outline-none">
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($existente)
                                            <a href="{{ route('empresa.nfse.show', $existente->id) }}" class="text-marca-700 hover:underline">
                                                {{ $existente->status === Nfse::AUTORIZADA ? 'Emitida (nº '.$existente->numero_nfse.')' : 'Enviando...' }}
                                            </a>
                                        @else
                                            <span class="text-slate-500">A emitir</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 px-4 py-4">
                    <p class="text-sm text-slate-600"><strong id="qtd">0</strong> nota(s) marcada(s) · total <strong id="total" class="tabular-nums">R$ 0,00</strong>
                        <span class="block text-xs text-slate-500">Saldo: {{ $saldo }} crédito(s). Cada nota autorizada usa 1 crédito.</span></p>
                    <button type="submit" class="rounded-lg bg-marca-600 px-5 py-3 font-medium text-white hover:bg-marca-700">Emitir NFS-e de {{ $nomeMes }}</button>
                </div>
            </section>
            <p class="mt-2 text-xs text-slate-500">Clientes que já têm NFS-e nesta competência ficam de fora, para não emitir em dobro. O valor alterado aqui vale só para este mês;
                para mudar o valor fixo, edite o cliente mensal ou use o reajuste abaixo.</p>
        </form>

        {{-- Reajuste anual --}}
        <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="font-medium">Reajuste anual</h2>
            <p class="mt-1 text-sm text-slate-500">Aplica um percentual sobre o valor mensal dos clientes <strong>marcados na lista acima</strong> (ex.: no reajuste de abril). O valor antigo fica guardado.</p>
            <form method="POST" action="{{ route('empresa.nfse.lote.reajustar') }}" class="mt-4 flex flex-wrap items-end gap-3" id="form-reajuste"
                  onsubmit="return prepararReajuste(this)">
                @csrf
                <div>
                    <label for="percentual" class="block text-sm font-medium text-slate-700">Percentual (%)</label>
                    <input type="text" id="percentual" name="percentual" inputmode="decimal" placeholder="Ex.: 5,5" value="{{ old('percentual') }}"
                           class="mt-1 w-32 rounded-lg border border-slate-300 px-3 py-2 text-right shadow-sm focus:border-marca-500 focus:outline-none">
                    @error('percentual')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    @error('contratos')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <button class="rounded-lg border border-marca-600 px-4 py-2 font-medium text-marca-700 hover:bg-marca-50">Aplicar reajuste</button>
            </form>
            @if ($temReajuste)
                <form method="POST" action="{{ route('empresa.nfse.lote.desfazer') }}" class="mt-3" onsubmit="return confirm('Voltar todos os clientes reajustados para o valor anterior?')">
                    @csrf
                    <button class="text-sm text-slate-600 underline hover:text-slate-900">Desfazer último reajuste</button>
                </form>
            @endif
        </section>
    @endif

    @include('partials.dinheiro-js')
    <script>
        function prepararReajuste(form) {
            form.querySelectorAll('input[name="contratos[]"]').forEach((el) => el.remove());
            const marcados = [...document.querySelectorAll('[data-marcar]:checked')];
            if (!marcados.length) { alert('Marque na lista os clientes que terão o reajuste.'); return false; }
            marcados.forEach((c) => {
                const h = document.createElement('input');
                h.type = 'hidden'; h.name = 'contratos[]'; h.value = c.dataset.id;
                form.appendChild(h);
            });
            const pct = document.getElementById('percentual').value || '?';
            return confirm(`Aplicar reajuste de ${pct}% em ${marcados.length} cliente(s)?`);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('form-lote');
            if (!form) return;
            const todos = document.getElementById('marcar-todos');
            const caixas = [...form.querySelectorAll('[data-marcar]')];
            const atualizar = () => {
                let qtd = 0, total = 0;
                caixas.forEach((c) => {
                    if (!c.checked) return;
                    qtd++;
                    total += lerDinheiro(c.closest('tr').querySelector('[data-valor]'));
                });
                document.getElementById('qtd').textContent = qtd;
                document.getElementById('total').textContent = 'R$ ' + mostrarDinheiro(total);
                todos.checked = caixas.length > 0 && caixas.every((c) => c.checked);
            };
            todos.addEventListener('change', () => { caixas.forEach((c) => { c.checked = todos.checked; }); atualizar(); });
            form.addEventListener('input', atualizar);
            form.addEventListener('change', atualizar);
            atualizar();
        });
    </script>
@endsection
