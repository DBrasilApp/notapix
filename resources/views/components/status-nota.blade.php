@props(['nota'])

@php
    $cores = [
        'rascunho' => 'bg-slate-100 text-slate-700',
        'processando' => 'bg-sky-100 text-sky-800',
        'autorizada' => 'bg-marca-100 text-marca-900',
        'rejeitada' => 'bg-red-100 text-red-800',
        'denegada' => 'bg-red-100 text-red-800',
        'cancelada' => 'bg-slate-200 text-slate-600 line-through',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-block rounded-full px-2.5 py-0.5 text-xs font-medium '.($cores[$nota->status] ?? 'bg-slate-100 text-slate-700')]) }}>
    {{ $nota->statusLabel() }}
</span>
