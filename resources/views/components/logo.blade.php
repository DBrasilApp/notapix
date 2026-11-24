<a href="{{ route('inicio') }}" {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 font-semibold tracking-tight text-slate-900']) }}>
    <span class="grid h-8 w-8 place-items-center rounded-lg bg-marca-600 text-sm font-bold text-white">NF</span>
    <span class="text-lg">{{ config('app.name') }}</span>
</a>
