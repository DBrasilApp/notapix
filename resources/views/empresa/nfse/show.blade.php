@extends('layouts.app')

@php
    use App\Models\Nfse;
    use App\Support\Formata;
    use App\Support\TabelaServicosNacional;
    $explicacao = \App\Services\Nfse\RejeicoesSefin::explicar($nfse->codigo_status, $nfse);
@endphp

@section('titulo', 'NFS-e')

@section('conteudo')
    @include('empresa.nfse.partials.abas', ['aba' => 'emitidas'])

    <div class="mt-6 max-w-4xl">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">
                    {{ $nfse->numero_nfse ? 'NFS-e nº '.$nfse->numero_nfse : 'NFS-e (DPS '.($nfse->numero_dps ?? '-').')' }}
                </h1>
                <p class="mt-1 text-sm text-slate-500">{{ $nfse->tomadorNome() }} · competência {{ $nfse->competenciaTexto() }} · {{ Formata::moeda($nfse->valor_servico) }}</p>
            </div>
            <x-status-nota :nota="$nfse" class="text-sm" />
        </div>

        {{-- Situação --}}
        @if ($nfse->status === Nfse::AUTORIZADA)
            <div class="mt-6 rounded-2xl border border-marca-100 bg-marca-50 p-5">
                <p class="font-medium text-marca-900">NFS-e autorizada{{ $nfse->autorizada_em ? ' em '.$nfse->autorizada_em->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i') : '' }}.</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ route('empresa.nfse.pdf', $nfse->id) }}" target="_blank" class="rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Ver PDF (DANFSe)</a>
                    <a href="{{ route('empresa.nfse.xml', $nfse->id) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-slate-700 hover:bg-slate-100">Baixar XML</a>
                    <a href="{{ route('empresa.nfse.create', ['copiar' => $nfse->id]) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-slate-700 hover:bg-slate-100">Emitir outra igual</a>
                </div>
                {{-- E-mail --}}
                <div class="mt-4 rounded-xl border border-marca-100 bg-white p-4">
                    <p class="text-sm">
                        @if ($nfse->email_enviado_em)
                            <span class="text-marca-900">E-mail enviado em {{ $nfse->email_enviado_em->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} para {{ $nfse->email_destinos }}.</span>
                        @elseif ($nfse->email_erro)
                            <span class="text-red-700">O e-mail não foi enviado: {{ $nfse->email_erro }}</span>
                        @else
                            <span class="text-slate-600">O e-mail com o XML e o PDF ainda não foi enviado{{ empty($nfse->tomador_dados['email']) ? ' (o tomador está sem e-mail no cadastro)' : '' }}.</span>
                        @endif
                    </p>
                    <form method="POST" action="{{ route('empresa.nfse.email', $nfse->id) }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        <input type="text" name="emails" value="{{ old('emails', $nfse->tomador_dados['email'] ?? '') }}" placeholder="email@cliente.com.br (separe vários com vírgula)"
                               class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-marca-500 focus:outline-none">
                        <button class="rounded-lg border border-marca-600 px-4 py-2 text-sm font-medium text-marca-700 hover:bg-marca-50">
                            {{ $nfse->email_enviado_em ? 'Enviar de novo' : 'Enviar por e-mail' }}
                        </button>
                    </form>
                    @error('emails')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <p class="mt-4 text-xs text-slate-600">Chave de acesso: <span class="font-mono">{{ $nfse->chave }}</span></p>
                <p class="mt-1 text-xs text-slate-600">O cliente pode conferir a nota em <a class="underline" href="{{ \App\Services\Nfse\GeradorDanfse::URL_CONSULTA.$nfse->chave }}" target="_blank" rel="noopener">nfse.gov.br</a>.</p>
            </div>
        @elseif ($nfse->status === Nfse::CANCELADA)
            <div class="mt-6 rounded-2xl border border-slate-300 bg-slate-100 p-5 text-sm text-slate-700">
                <p class="font-medium">NFS-e cancelada{{ $nfse->cancelada_em ? ' em '.$nfse->cancelada_em->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '' }}.</p>
                @if ($nfse->cancelamento_motivo)<p class="mt-1">Motivo: {{ $nfse->cancelamento_motivo }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-3">
                    <a href="{{ route('empresa.nfse.pdf', $nfse->id) }}" target="_blank" class="rounded-lg border border-slate-300 bg-white px-4 py-2 hover:bg-slate-50">PDF (com tarja de cancelada)</a>
                    <a href="{{ route('empresa.nfse.xml', $nfse->id) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 hover:bg-slate-50">XML da NFS-e</a>
                </div>
            </div>
        @elseif ($nfse->status === Nfse::PROCESSANDO)
            <div class="mt-6 rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm text-sky-900">
                <p class="font-medium">Aguardando a SEFIN Nacional.</p>
                <p class="mt-1">{{ $nfse->motivo }}</p>
                <form method="POST" action="{{ route('empresa.nfse.consultar', $nfse->id) }}" class="mt-3">
                    @csrf
                    <button class="rounded-lg bg-sky-700 px-4 py-2 font-medium text-white hover:bg-sky-800">Consultar situação</button>
                </form>
            </div>
        @elseif ($nfse->status === Nfse::REJEITADA)
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                @if ($explicacao)
                    <p class="text-base font-semibold">{{ $explicacao['titulo'] }}</p>
                    <p class="mt-2"><span class="font-medium">O que fazer:</span> {{ $explicacao['acao'] }}</p>
                    @if ($explicacao['url'] && $explicacao['botao'] !== 'Corrigir a nota')
                        <a href="{{ $explicacao['url'] }}" class="mt-3 inline-block rounded-lg bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800">{{ $explicacao['botao'] }}</a>
                    @endif
                    <p class="mt-3 text-xs text-red-800/80">Mensagem da SEFIN Nacional: {{ $nfse->motivo }}</p>
                @else
                    <p class="font-medium">A SEFIN Nacional não autorizou a nota{{ $nfse->codigo_status ? ' (código '.$nfse->codigo_status.')' : '' }}:</p>
                    <p class="mt-1 whitespace-pre-line">{{ $nfse->motivo }}</p>
                @endif
                <p class="mt-3 text-xs">O crédito foi devolvido. Depois de corrigir, envie de novo.</p>
                <a href="{{ route('empresa.nfse.edit', $nfse->id) }}" class="mt-3 inline-block rounded-lg border border-red-300 bg-white px-4 py-2 font-medium text-red-800 hover:bg-red-100">Corrigir e enviar de novo</a>
            </div>
        @endif

        {{-- Dados da nota --}}
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Tomador</dt><dd class="font-medium">{{ $nfse->tomadorNome() }}</dd><dd class="text-slate-500">{{ $nfse->tomadorDocumento() }}</dd></div>
                <div><dt class="text-slate-500">Serviço</dt><dd class="font-medium">{{ $nfse->servico?->nome }}</dd>
                    <dd class="text-slate-500">{{ TabelaServicosNacional::formatar($nfse->ctribnac) }}{{ $nfse->ctribmun ? '.'.$nfse->ctribmun : '' }} - {{ TabelaServicosNacional::descricao($nfse->ctribnac) }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Descrição</dt><dd class="whitespace-pre-line">{{ $nfse->discriminacao }}</dd></div>
                @if ($nfse->informacoes_complementares)
                    <div class="sm:col-span-2"><dt class="text-slate-500">Informações complementares</dt><dd class="whitespace-pre-line">{{ $nfse->informacoes_complementares }}</dd></div>
                @endif
            </dl>

            <table class="mt-6 w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                    <tr><td class="py-2 text-slate-500">Valor do serviço</td><td class="py-2 text-right tabular-nums">{{ Formata::moeda($nfse->valor_servico) }}</td></tr>
                    <tr><td class="py-2 text-slate-500">ISS {{ $nfse->iss_retido ? 'retido pelo tomador ('.number_format((float) $nfse->aliquota_iss, 2, ',', '.').'%)' : '(não retido)' }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $nfse->iss_retido ? '- '.Formata::moeda($nfse->valor_iss_retido) : '-' }}</td></tr>
                    @foreach (Nfse::RETENCOES as $campo => $rotulo)
                        @if ((float) $nfse->{$campo} > 0)
                            <tr><td class="py-2 text-slate-500">Retenção de {{ $rotulo }}</td><td class="py-2 text-right tabular-nums">- {{ Formata::moeda($nfse->{$campo}) }}</td></tr>
                        @endif
                    @endforeach
                    <tr class="font-semibold"><td class="py-2">Valor líquido</td><td class="py-2 text-right tabular-nums">{{ Formata::moeda($nfse->valor_liquido) }}</td></tr>
                </tbody>
            </table>
            <p class="mt-4 text-xs text-slate-500">DPS série {{ (int) $nfse->serie }} nº {{ $nfse->numero_dps ?? '-' }} · {{ (int) $nfse->ambiente === 2 ? 'homologação' : 'produção' }}</p>
        </section>

        {{-- Cancelamento --}}
        @if ($nfse->status === Nfse::AUTORIZADA)
            <details class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @if ($errors->hasAny(['motivo', 'justificativa'])) open @endif>
                <summary class="cursor-pointer font-medium text-red-700">Cancelar esta NFS-e</summary>
                <form method="POST" action="{{ route('empresa.nfse.cancelar', $nfse->id) }}" class="mt-4 space-y-4"
                      onsubmit="return confirm('Cancelar a NFS-e? Esta ação não pode ser desfeita e o crédito não é devolvido.')">
                    @csrf
                    <x-selecao label="Motivo" name="motivo">
                        <option value="1" @selected(old('motivo') === '1')>Erro na emissão</option>
                        <option value="2" @selected(old('motivo') === '2')>Serviço não prestado</option>
                        <option value="9" @selected(old('motivo') === '9')>Outros</option>
                    </x-selecao>
                    <div>
                        <label for="justificativa" class="block text-sm font-medium text-slate-700">Explique o motivo (15 a 255 letras)</label>
                        <textarea id="justificativa" name="justificativa" rows="2" maxlength="255" required
                                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">{{ old('justificativa') }}</textarea>
                        @error('justificativa')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <p class="text-xs text-slate-500">Algumas prefeituras não aceitam o cancelamento direto: nesse caso o pedido precisa ser feito no portal da NFS-e Nacional (análise fiscal).
                        Se a nota está errada, você também pode emitir uma nova e cancelar esta.</p>
                    <button class="rounded-lg bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800">Cancelar NFS-e</button>
                </form>
            </details>
        @endif
    </div>
@endsection
