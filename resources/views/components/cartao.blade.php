@props(['titulo', 'valor', 'detalhe' => null])

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="text-sm text-slate-500">{{ $titulo }}</div>
    <div class="mt-1 text-3xl font-semibold tracking-tight">{{ $valor }}</div>
    @if ($detalhe)
        <div class="mt-1 text-xs text-slate-500">{{ $detalhe }}</div>
    @endif
</div>
