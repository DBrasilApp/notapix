@extends('layouts.app')

@section('titulo', 'Adicionar empresa')

@section('conteudo')
    <div class="max-w-3xl">
        <a href="{{ route('escritorio.painel') }}" class="text-sm text-slate-500 hover:text-marca-700">← Painel do escritório</a>
        <h1 class="mt-2 text-2xl font-semibold">Adicionar empresa-cliente</h1>
        <p class="mt-1 text-sm text-slate-600">A empresa já fica ligada ao seu escritório. Depois é só acessá-la e enviar o certificado digital.</p>

        @if ($errors->any())
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-medium">Corrija os pontos abaixo:</p>
                <ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('escritorio.empresas.store') }}" class="mt-6 space-y-6">
            @csrf

            @include('empresa.partials.formulario-dados', [
                'empresa' => $empresa, 'ufs' => $ufs, 'modo' => 'escritorio',
                'urlCnpj' => route('escritorio.cnpj', '00000000000000'),
            ])

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="flex items-center gap-2 font-medium">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marca-600 text-xs text-white">4</span>
                    Acesso do cliente (opcional)
                </h2>
                <label class="mt-3 inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="criar_acesso" value="0">
                    <input type="checkbox" name="criar_acesso" value="1" id="criar_acesso" class="rounded border-slate-300" @checked(old('criar_acesso', '1') === '1')>
                    O cliente também vai entrar no NotaPix (para emitir ou consultar as notas dele)
                </label>
                <div id="bloco-acesso" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-campo label="Nome do responsável" name="responsavel" maxlength="150" />
                    <x-campo label="E-mail de acesso do cliente" name="email_acesso" type="email" maxlength="150"
                             ajuda="Enviamos um link para ele criar a senha." />
                </div>
            </section>

            <div class="flex justify-end gap-3">
                <a href="{{ route('escritorio.painel') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700 hover:bg-slate-100">Cancelar</a>
                <button class="rounded-lg bg-marca-600 px-5 py-2.5 font-medium text-white hover:bg-marca-700">Cadastrar empresa</button>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const marcar = document.getElementById('criar_acesso');
            const bloco = document.getElementById('bloco-acesso');
            const atualizar = () => bloco.classList.toggle('hidden', !marcar.checked);
            marcar.addEventListener('change', atualizar);
            atualizar();
        })();
    </script>
@endsection
