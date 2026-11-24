@if (session('sucesso'))
    <div class="mb-4 rounded-lg border border-marca-100 bg-marca-50 px-4 py-3 text-sm text-marca-900">
        {{ session('sucesso') }}
    </div>
@endif
@if (session('erro'))
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ session('erro') }}
    </div>
@endif
@if (session('aviso'))
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ session('aviso') }}
    </div>
@endif
