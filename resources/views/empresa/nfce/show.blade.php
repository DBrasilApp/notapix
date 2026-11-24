@extends('layouts.app')

@php use App\Models\Nota; use App\Support\Formata; @endphp

@section('titulo', 'NFC-e '.($nota->numero ?? ''))

@section('conteudo')
    <div class="mx-auto max-w-4xl">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('empresa.nfce.index') }}" class="text-sm text-slate-500 hover:text-marca-700">← Cupons emitidos</a>
                <h1 class="mt-1 text-2xl font-semibold">NFC-e {{ $nota->numero }} <span class="text-base font-normal text-slate-500">série {{ $nota->serie }}</span></h1>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-status-nota :nota="$nota" />
                    <span class="text-sm text-slate-600">{{ Formata::moeda($nota->valor_total) }}</span>
                    @if ((float) $nota->troco > 0)<span class="text-sm text-slate-600">· troco {{ Formata::moeda($nota->troco) }}</span>@endif
                    @if ($nota->ambiente === 2)<span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900">Homologação - sem valor fiscal</span>@endif
                </div>
            </div>
            <a href="{{ route('empresa.nfce.create') }}" class="rounded-xl bg-marca-600 px-5 py-3 font-semibold text-white hover:bg-marca-700" autofocus>Nova venda</a>
        </div>

        @if ($errors->has('cancelamento'))
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first('cancelamento') }}</div>
        @endif

        @if (in_array($nota->status, [Nota::AUTORIZADA, Nota::CANCELADA], true))
            <div class="mt-6 grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <iframe id="cupom" src="{{ route('empresa.notas.danfe', $nota->id) }}" title="Cupom NFC-e"
                            class="h-[38rem] w-full rounded-xl border border-slate-200 bg-white"></iframe>
                </div>
                <div class="space-y-3">
                    <button type="button" id="imprimir" class="w-full rounded-lg bg-slate-900 px-4 py-3 font-medium text-white hover:bg-slate-800">Imprimir cupom</button>
                    <a href="{{ route('empresa.notas.danfe', $nota->id) }}" target="_blank" class="block w-full rounded-lg border border-slate-300 px-4 py-2.5 text-center text-slate-700 hover:bg-slate-100">Abrir PDF</a>
                    <a href="{{ route('empresa.notas.xml', $nota->id) }}" class="block w-full rounded-lg border border-slate-300 px-4 py-2.5 text-center text-slate-700 hover:bg-slate-100">Baixar XML</a>
                    @if ($linkConsulta)
                        @php($mensagem = 'Sua NFC-e nº '.$nota->numero.' de '.$nota->empresa->razao_social.', no valor de '.Formata::moeda($nota->valor_total).'. Para consultar: '.$linkConsulta)
                        <a href="https://wa.me/?text={{ rawurlencode($mensagem) }}" target="_blank"
                           class="block w-full rounded-lg border border-slate-300 px-4 py-2.5 text-center text-slate-700 hover:bg-slate-100">Enviar por WhatsApp</a>
                        <a href="{{ $linkConsulta }}" target="_blank" class="block text-center text-xs text-marca-700 underline">Ver como o cliente vê (site da SEFAZ)</a>
                    @endif

                    @if ($nota->status === Nota::AUTORIZADA)
                        <details class="rounded-xl border border-slate-200 bg-white p-4" @if ($errors->has('cancelamento') || old('justificativa')) open @endif>
                            <summary class="cursor-pointer text-sm font-medium text-red-800">Cancelar NFC-e</summary>
                            @if ($impedimentoCancelamento)
                                <p class="mt-2 text-sm text-slate-600">{{ $impedimentoCancelamento }}</p>
                            @else
                                <p class="mt-2 text-xs text-slate-600">Pode cancelar até {{ $limiteCancelamento?->format('H:i') }} (30 minutos após a autorização). O crédito usado não volta.</p>
                                <form method="POST" action="{{ route('empresa.notas.cancelar', $nota->id) }}" class="mt-2"
                                      onsubmit="if (!confirm('Cancelar a NFC-e {{ $nota->numero }}?')) return false; this.querySelector('button').disabled = true">
                                    @csrf
                                    <textarea name="justificativa" rows="2" minlength="15" maxlength="255" required placeholder="Motivo (ex.: cliente desistiu da compra)"
                                              class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('justificativa') }}</textarea>
                                    <button class="mt-2 w-full rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Cancelar na SEFAZ</button>
                                </form>
                            @endif
                        </details>
                    @else
                        <p class="rounded-xl bg-slate-100 p-3 text-sm text-slate-700">Cupom cancelado na SEFAZ. {{ $nota->motivo }}</p>
                    @endif

                    <dl class="rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                        <dt>Chave de acesso</dt><dd class="break-all font-mono">{{ trim(chunk_split((string) $nota->chave, 4, ' ')) }}</dd>
                        <dt class="mt-2">Protocolo</dt><dd>{{ $nota->protocolo }} · {{ $nota->autorizada_em?->format('d/m/Y H:i') }}</dd>
                    </dl>
                </div>
            </div>
        @elseif ($nota->status === Nota::PROCESSANDO)
            <div class="mt-6 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                <p class="font-medium">A SEFAZ não respondeu ao envio.</p>
                <p class="mt-1 break-words">{{ $nota->motivo }}</p>
                <form method="POST" action="{{ route('empresa.notas.consultar', $nota->id) }}" class="mt-3">@csrf
                    <button class="rounded-lg bg-sky-600 px-4 py-2 font-medium text-white hover:bg-sky-700">Consultar situação</button>
                </form>
            </div>
        @else
            <x-rejeicao :nota="$nota" class="mt-6" titulo="A SEFAZ não autorizou o cupom"
                        :rodape="'O crédito foi devolvido. O número '.$nota->numero.' continua reservado para esta venda.'">
                @if ($nota->podeEditar())
                    <a href="{{ route('empresa.nfce.edit', $nota->id) }}" class="mt-3 inline-block rounded-lg border border-red-300 bg-white px-4 py-2 font-medium text-red-800 hover:bg-red-100">Abrir a venda e enviar de novo</a>
                @endif
            </x-rejeicao>
        @endif
    </div>

    <script>
        document.getElementById('imprimir')?.addEventListener('click', () => {
            const cupom = document.getElementById('cupom');
            try { cupom.contentWindow.focus(); cupom.contentWindow.print(); }
            catch (e) { window.open(cupom.src, '_blank'); }
        });
    </script>
@endsection
