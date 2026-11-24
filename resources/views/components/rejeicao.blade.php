{{-- Rejeição/denegação da SEFAZ explicada em linguagem simples, com o texto técnico abaixo --}}
@props(['nota', 'titulo' => 'A SEFAZ não autorizou a nota', 'rodape' => null])
@php($explicacao = \App\Support\RejeicoesSefaz::explicar($nota->codigo_status, $nota))

<div {{ $attributes->merge(['class' => 'rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900']) }}>
    @if ($explicacao)
        <p class="text-base font-semibold">{{ $explicacao['titulo'] }}</p>
        <p class="mt-2"><span class="font-medium">O que fazer:</span> {{ $explicacao['acao'] }}</p>
        @if ($explicacao['url'])
            <a href="{{ $explicacao['url'] }}" class="mt-3 inline-block rounded-lg bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800">{{ $explicacao['botao'] }}</a>
        @endif
        <p class="mt-3 text-xs text-red-800/80">Mensagem da SEFAZ{{ $nota->codigo_status ? ' (código '.$nota->codigo_status.')' : '' }}: {{ $nota->motivo }}</p>
    @else
        <p class="font-medium">{{ $titulo }}{{ $nota->codigo_status ? ' (código '.$nota->codigo_status.')' : '' }}:</p>
        <p class="mt-1">{{ $nota->motivo }}</p>
    @endif
    @if ($rodape)
        <p class="mt-2 text-xs">{{ $rodape }}</p>
    @endif
    {{ $slot }}
</div>
