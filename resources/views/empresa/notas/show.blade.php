@extends('layouts.app')

@php use App\Models\Nota; use App\Support\Formata; @endphp

@section('titulo', $nota->numero ? 'NF-e '.$nota->numeroFormatado() : 'Rascunho')

@section('conteudo')
    <div class="max-w-5xl">
        <a href="{{ route('empresa.notas.index') }}" class="text-sm text-slate-500 hover:text-marca-700">← Notas fiscais</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">
                    {{ $nota->numero ? 'NF-e '.$nota->numeroFormatado() : 'Rascunho' }}
                    @if ($nota->numero)<span class="text-base font-normal text-slate-500">série {{ $nota->serie }}</span>@endif
                </h1>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-status-nota :nota="$nota" />
                    @if ($nota->ambiente === 2)<span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900">Homologação - sem valor fiscal</span>@endif
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @if (in_array($nota->status, [Nota::AUTORIZADA, Nota::CANCELADA], true))
                    <a href="{{ route('empresa.notas.danfe', $nota->id) }}" target="_blank" class="rounded-lg bg-marca-600 px-4 py-2 font-medium text-white hover:bg-marca-700">DANFE (PDF)</a>
                    <a href="{{ route('empresa.notas.xml', $nota->id) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-100">Baixar XML</a>
                @endif
                @if ($nota->status === Nota::PROCESSANDO)
                    <form method="POST" action="{{ route('empresa.notas.consultar', $nota->id) }}">
                        @csrf
                        <button class="rounded-lg bg-sky-600 px-4 py-2 font-medium text-white hover:bg-sky-700">Consultar situação</button>
                    </form>
                @endif
                @if ($nota->podeEditar())
                    <a href="{{ route('empresa.notas.edit', $nota->id) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-100">{{ $nota->status === Nota::REJEITADA ? 'Corrigir' : 'Editar' }}</a>
                    <form method="POST" action="{{ route('empresa.notas.transmitir', $nota->id) }}" onsubmit="this.querySelector('button').disabled = true">
                        @csrf
                        <button class="rounded-lg bg-marca-600 px-4 py-2 font-medium text-white hover:bg-marca-700">{{ $nota->status === Nota::REJEITADA ? 'Enviar de novo' : 'Emitir nota' }}</button>
                    </form>
                @endif
                @if ($nota->podeExcluir())
                    <form method="POST" action="{{ route('empresa.notas.destroy', $nota->id) }}" onsubmit="return confirm('Excluir este rascunho?')">
                        @csrf @method('DELETE')
                        <button class="rounded-lg px-3 py-2 text-sm text-red-700 hover:bg-red-50">Excluir rascunho</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Mensagens da emissão --}}
        @if ($errors->has('emissao') || $errors->has('nota'))
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-medium">Não foi possível enviar:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach (array_merge($errors->get('emissao'), $errors->get('nota')) as $erro)<li>{{ $erro }}</li>@endforeach
                </ul>
            </div>
        @endif

        @if ($nota->status === Nota::REJEITADA && $nota->motivo)
            <x-rejeicao :nota="$nota" class="mt-6"
                        :rodape="'O crédito foi devolvido. Corrija e envie de novo: o número '.$nota->numeroFormatado().' continua reservado para esta nota.'" />
        @elseif ($nota->status === Nota::PROCESSANDO)
            @php($naFila = (int) $nota->tentativas === 0)
            @php($minutos = (int) $nota->updated_at->diffInMinutes(now()))
            <div class="mt-6 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900" id="aviso-processando">
                @if ($naFila)
                    Aguardando envio para a SEFAZ...
                    @if ($minutos >= 2)
                        <span class="mt-1 block text-red-700">A nota está na fila há {{ $minutos }} minutos e nada a processou. Verifique se a fila está rodando (QUEUE_CONNECTION=sync no .env, ou "php artisan queue:work" no servidor).</span>
                    @else
                        <span class="block text-xs">Esta página se atualiza sozinha.</span>
                        <script>setTimeout(() => location.reload(), 5000);</script>
                    @endif
                @else
                    <p class="font-medium">A SEFAZ não respondeu ao envio.</p>
                    <p class="mt-1 break-words">{{ $nota->motivo }}</p>
                    <p class="mt-2 text-xs">O crédito continua reservado até sabermos se a SEFAZ recebeu a nota. Clique em "Consultar situação": se ela não tiver recebido, a nota volta para correção e o crédito é devolvido.</p>
                @endif
            </div>
        @elseif ($nota->status === Nota::DENEGADA)
            <x-rejeicao :nota="$nota" class="mt-6" titulo="Uso denegado pela SEFAZ"
                        rodape="A denegação indica irregularidade cadastral do emitente ou do destinatário. Este número não pode ser reutilizado." />
        @elseif ($problemas && $nota->status === Nota::RASCUNHO)
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-medium">Antes de emitir:</p>
                <ul class="mt-1 list-disc pl-5">@foreach ($problemas as $p)<li>{{ $p }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($errors->has('cancelamento') || $errors->has('correcao'))
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                @foreach (array_merge($errors->get('cancelamento'), $errors->get('correcao')) as $erro)<p>{{ $erro }}</p>@endforeach
            </div>
        @endif

        @if ($nota->status === Nota::CANCELADA)
            <div class="mt-6 rounded-xl border border-slate-300 bg-slate-100 p-4 text-sm text-slate-800">
                <p class="font-medium">Nota cancelada na SEFAZ.</p>
                <p class="mt-1">{{ $nota->motivo }}</p>
                <p class="mt-2 text-xs text-slate-600">O número {{ $nota->numeroFormatado() }} não pode ser reutilizado. O crédito usado na emissão não é devolvido.</p>
            </div>
        @endif

        {{-- Cancelar / carta de correção --}}
        @if ($nota->status === Nota::AUTORIZADA)
            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <details class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @if ($errors->has('correcao') || old('correcao')) open @endif>
                    <summary class="cursor-pointer font-medium">Carta de correção</summary>
                    @if ($impedimentoCarta)
                        <p class="mt-3 text-sm text-slate-600">{{ $impedimentoCarta }}</p>
                    @else
                        <div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                            <p><strong>Serve para corrigir</strong> erros de texto: descrição do produto, endereço, dados do transporte, informações complementares, CFOP com o mesmo sentido...</p>
                            <p class="mt-1"><strong>Não pode corrigir:</strong> valores, quantidades, impostos, CNPJ/CPF ou nome de quem emite ou recebe, data de emissão ou de saída. Nesses casos, cancele e emita de novo.</p>
                            @if (\App\Services\Nfe\EventosNfe::cartasRegistradas($nota) > 0)
                                <p class="mt-1 text-amber-800">Uma nova carta substitui a anterior: escreva <strong>todas</strong> as correções, inclusive as das cartas que já foram enviadas.</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('empresa.notas.corrigir', $nota->id) }}" class="mt-3" onsubmit="this.querySelector('button').disabled = true">
                            @csrf
                            <label for="correcao" class="text-sm font-medium text-slate-700">O que está errado e qual é o certo</label>
                            <textarea id="correcao" name="correcao" rows="4" minlength="15" maxlength="1000" required
                                      placeholder="Ex.: No item 1, onde se lê CAMISETA AZUL, leia-se CAMISETA AZUL MARINHO."
                                      class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('correcao') }}</textarea>
                            <button class="mt-3 rounded-lg bg-marca-600 px-4 py-2 text-sm font-medium text-white hover:bg-marca-700">Enviar carta de correção</button>
                        </form>
                    @endif
                </details>

                <details class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @if ($errors->has('cancelamento') || old('justificativa')) open @endif>
                    <summary class="cursor-pointer font-medium text-red-800">Cancelar nota</summary>
                    @if ($impedimentoCancelamento)
                        <p class="mt-3 text-sm text-slate-600">{{ $impedimentoCancelamento }}</p>
                    @else
                        <p class="mt-3 text-xs text-slate-600">
                            Pode cancelar até {{ $nota->autorizada_em?->copy()->addHours(config('nfeexpress.nfe.prazo_cancelamento_horas'))->format('d/m/Y \à\s H:i') }},
                            se a mercadoria ainda não saiu. O cancelamento não pode ser desfeito e o crédito usado não volta.
                        </p>
                        <form method="POST" action="{{ route('empresa.notas.cancelar', $nota->id) }}" class="mt-3"
                              onsubmit="if (!confirm('Cancelar a NF-e {{ $nota->numeroFormatado() }}? Não dá para desfazer.')) return false; this.querySelector('button').disabled = true">
                            @csrf
                            <label for="justificativa" class="text-sm font-medium text-slate-700">Motivo do cancelamento</label>
                            <textarea id="justificativa" name="justificativa" rows="3" minlength="15" maxlength="255" required
                                      placeholder="Ex.: Venda desfeita, o cliente desistiu da compra."
                                      class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('justificativa') }}</textarea>
                            <button class="mt-3 rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Cancelar nota na SEFAZ</button>
                        </form>
                    @endif
                </details>
            </div>
        @endif

        {{-- Histórico de eventos --}}
        @if ($nota->eventos->isNotEmpty())
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-medium">Cancelamento e cartas de correção</h2>
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($nota->eventos as $evento)
                        <li class="flex flex-wrap items-start justify-between gap-3 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">
                                    {{ $evento->tipo === 'cancelamento' ? 'Cancelamento' : 'Carta de correção nº '.$evento->sequencia }}
                                    <span class="ml-1 rounded-full px-2 py-0.5 text-xs {{ $evento->status === 'registrado' ? 'bg-marca-100 text-marca-900' : 'bg-red-100 text-red-800' }}">
                                        {{ $evento->status === 'registrado' ? 'Registrado' : 'Não aceito' }}
                                    </span>
                                </p>
                                <p class="mt-1 break-words text-slate-600">{{ $evento->texto }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $evento->created_at->format('d/m/Y H:i') }}
                                    @if ($evento->protocolo) · Protocolo {{ $evento->protocolo }} @endif
                                    @if ($evento->status !== 'registrado') · {{ $evento->codigo_status ? 'Código '.$evento->codigo_status.': ' : '' }}{{ $evento->motivo }} @endif
                                </p>
                            </div>
                            @if ($evento->status === 'registrado' && $evento->xml_path)
                                <div class="flex gap-2">
                                    <a href="{{ route('empresa.notas.evento.pdf', [$nota->id, $evento->id]) }}" target="_blank" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-100">PDF</a>
                                    <a href="{{ route('empresa.notas.evento.xml', [$nota->id, $evento->id]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-100">XML</a>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Dados --}}
        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                <h2 class="font-medium">Destinatário</h2>
                <p class="mt-2">{{ $nota->nomeDestinatario() }}</p>
                <p class="text-sm text-slate-500">{{ $nota->documentoDestinatario() }}</p>
                @php($d = $nota->destinatario_dados ?? [])
                <p class="mt-1 text-sm text-slate-600">
                    {{ $d['logradouro'] ?? '' }}, {{ $d['numero'] ?? '' }} {{ $d['complemento'] ?? '' }} - {{ $d['bairro'] ?? '' }},
                    {{ $d['municipio'] ?? '' }}/{{ $d['uf'] ?? '' }}
                </p>
                <p class="mt-3 text-sm text-slate-600">{{ $nota->operacaoLabel() }} · {{ $nota->natureza_operacao }} · {{ $nota->id_dest === 2 ? 'Interestadual' : 'Dentro do estado' }}@if ($nota->operacao === 'venda' || ! $nota->operacao) · {{ $nota->ind_final ? 'Consumidor final' : 'Revenda/industrialização' }}@endif</p>
                @if ($nota->ehDevolucao() && $nota->referencias)
                    <div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm">
                        <p class="font-medium text-slate-700">Nota de compra devolvida</p>
                        @foreach ($nota->referencias as $ref)
                            @if (($ref['tipo'] ?? 'nfe') === 'nf')
                                <p class="text-slate-600">Nota em papel nº {{ $ref['numero'] }}, série {{ $ref['serie'] }}, de {{ $ref['mes_ano'] }} (CNPJ {{ Formata::cnpj($ref['cnpj']) }})</p>
                            @else
                                @php($d = \App\Support\ChaveAcesso::decompor($ref['chave']))
                                <p class="text-slate-600">@if ($d)NF-e nº {{ $d['numero'] }}, série {{ $d['serie'] }}, de {{ $d['mes'] }}/{{ $d['ano'] }} · @endif<span class="font-mono text-xs">{{ trim(chunk_split($ref['chave'], 4, ' ')) }}</span></p>
                            @endif
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-medium">Resumo</h2>
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Produtos</dt><dd class="tabular-nums">{{ Formata::moeda($nota->valor_produtos) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Descontos</dt><dd class="tabular-nums">{{ Formata::moeda($nota->valor_desconto) }}</dd></div>
                    @if ((float) $nota->valor_icms > 0)<div class="flex justify-between"><dt class="text-slate-500">ICMS destacado</dt><dd class="tabular-nums">{{ Formata::moeda($nota->valor_icms) }}</dd></div>@endif
                    @if ((float) $nota->valor_icms_st > 0)<div class="flex justify-between"><dt class="text-slate-500">ICMS-ST</dt><dd class="tabular-nums">{{ Formata::moeda($nota->valor_icms_st) }}</dd></div>@endif
                    @if ((float) $nota->valor_ipi_devolvido > 0)<div class="flex justify-between"><dt class="text-slate-500">IPI devolvido</dt><dd class="tabular-nums">{{ Formata::moeda($nota->valor_ipi_devolvido) }}</dd></div>@endif
                    <div class="flex justify-between border-t border-slate-100 pt-1 font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ Formata::moeda($nota->valor_total) }}</dd></div>
                    <div class="flex justify-between pt-2"><dt class="text-slate-500">Pagamento</dt><dd>{{ Nota::FORMAS_PAGAMENTO[$nota->forma_pagamento] ?? $nota->forma_pagamento }}{{ $nota->ind_pagamento ? ' a prazo' : '' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Frete</dt><dd>{{ Nota::MODALIDADES_FRETE[$nota->modalidade_frete] ?? '' }}</dd></div>
                </dl>
            </section>
        </div>

        @if ($nota->chave && in_array($nota->status, [Nota::AUTORIZADA, Nota::DENEGADA, Nota::CANCELADA], true))
            <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2"><span class="text-slate-500">Chave de acesso</span><p class="font-mono tabular-nums">{{ $nota->chaveFormatada() }}</p></div>
                    <div><span class="text-slate-500">Protocolo</span><p class="tabular-nums">{{ $nota->protocolo }}</p>
                        @if ($nota->autorizada_em)<p class="text-xs text-slate-500">{{ $nota->autorizada_em->format('d/m/Y H:i') }}</p>@endif
                    </div>
                </div>
            </section>
        @endif

        <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-200 px-5 py-3 font-medium">Produtos</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">#</th>
                            <th class="px-5 py-2 font-medium">Produto</th>
                            <th class="px-5 py-2 font-medium">NCM</th>
                            <th class="px-5 py-2 font-medium">CFOP</th>
                            <th class="px-5 py-2 text-right font-medium">Qtd.</th>
                            <th class="px-5 py-2 text-right font-medium">Unitário</th>
                            <th class="px-5 py-2 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($nota->itens as $item)
                            <tr>
                                <td class="px-5 py-2 text-slate-500">{{ $item->numero_item }}</td>
                                <td class="px-5 py-2">{{ $item->descricao }} <span class="text-xs text-slate-500">({{ $item->codigo }})</span></td>
                                <td class="whitespace-nowrap px-5 py-2 text-slate-600">{{ Formata::ncm($item->ncm) }}</td>
                                <td class="px-5 py-2 text-slate-600">{{ \App\Models\Cfop::formatar($item->cfop) }}</td>
                                <td class="whitespace-nowrap px-5 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $item->quantidade, 4, ',', '.'), '0'), ',') }} {{ $item->unidade }}</td>
                                <td class="whitespace-nowrap px-5 py-2 text-right tabular-nums">{{ Formata::moeda($item->valor_unitario) }}</td>
                                <td class="whitespace-nowrap px-5 py-2 text-right tabular-nums">{{ Formata::moeda((float) $item->valor_total - (float) $item->valor_desconto) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($nota->informacoes_complementares)
            <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
                <h2 class="font-medium">Informações complementares</h2>
                <p class="mt-1 whitespace-pre-line text-slate-700">{{ $nota->informacoes_complementares }}</p>
            </section>
        @endif
    </div>
@endsection
