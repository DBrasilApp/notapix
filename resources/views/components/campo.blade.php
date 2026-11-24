@props(['label', 'name', 'type' => 'text', 'value' => null, 'ajuda' => null])

<div>
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none']) }}
    >
    @if ($ajuda)
        <p class="mt-1 text-xs text-slate-500">{{ $ajuda }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
