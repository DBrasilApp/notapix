@extends('layouts.app')

@section('titulo', 'Contador')

@section('conteudo')
    <div class="max-w-2xl">
        <h1 class="text-2xl font-semibold">Escritório contábil</h1>
        <p class="mt-1 text-sm text-slate-600">
            Autorize o seu escritório contábil a acessar a empresa no NotaPix: ele poderá emitir notas por você,
            baixar os XMLs do mês para a contabilidade e, se combinarem, repassar créditos.
        </p>

        @if ($vinculo)
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">{{ $vinculo->pivot->status === 'ativo' ? 'Escritório com acesso à empresa' : 'Aguardando o escritório aceitar' }}</p>
                <p class="mt-1 font-medium">{{ $vinculo->razao_social }}</p>
                <p class="text-sm text-slate-500">{{ \App\Support\Formata::tipoDocumento($vinculo->cnpj) }} {{ \App\Support\Formata::documento($vinculo->cnpj) }}@if ($vinculo->crc) · CRC {{ $vinculo->crc }}@endif</p>
                @if ($vinculo->pivot->status === 'ativo' && $vinculo->pivot->usar_saldo_escritorio)
                    <p class="mt-2 text-sm text-marca-800">Quando os seus créditos acabarem, as notas usam os créditos do escritório.</p>
                @endif

                <form method="POST" action="{{ route('empresa.contador.destroy') }}" class="mt-4"
                      onsubmit="return confirm('Remover o acesso deste escritório à sua empresa?')">
                    @csrf @method('DELETE')
                    <button class="rounded-lg border border-red-200 px-4 py-2 text-sm text-red-700 hover:bg-red-50">
                        {{ $vinculo->pivot->status === 'ativo' ? 'Remover acesso do escritório' : 'Cancelar pedido' }}
                    </button>
                </form>
            </section>
        @else
            <form method="POST" action="{{ route('empresa.contador.store') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                @csrf
                <x-campo label="CPF ou CNPJ do escritório contábil (ou do contador)" name="cnpj_escritorio" required inputmode="numeric" placeholder="CPF ou CNPJ"
                         ajuda="Contador autônomo usa o CPF. O escritório precisa ter conta no NotaPix; depois de enviado, ele aceita o pedido no painel dele." />
                <button class="mt-4 rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Autorizar escritório</button>
            </form>
        @endif
    </div>

    @include('partials.mascara-cpf-cnpj')
    <script>
        (() => {
            const doc = document.getElementById('cnpj_escritorio');
            if (!doc) return;
            doc.addEventListener('input', () => { doc.value = mascaraCpfCnpj(doc.value); });
            doc.value = mascaraCpfCnpj(doc.value);
        })();
    </script>
@endsection
