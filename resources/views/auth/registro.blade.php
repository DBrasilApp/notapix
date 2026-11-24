@extends('layouts.guest')

@php
    $tipoAtual = old('tipo', $tipo);
    $ehEmpresa = $tipoAtual === 'empresa';
@endphp

@section('titulo', 'Cadastro')
@section('largura', $ehEmpresa ? 'max-w-3xl' : 'max-w-md')

@section('conteudo')
    <h1 class="text-xl font-semibold">Criar conta</h1>
    <p class="mt-1 text-sm text-slate-500">Sem mensalidade: você paga só pelas notas que emitir.</p>

    {{-- Troca entre cadastro de empresa e de escritório --}}
    <div class="mt-6 grid max-w-md grid-cols-2 gap-2 rounded-lg bg-slate-100 p-1 text-sm">
        <a href="{{ route('registro', ['tipo' => 'empresa']) }}"
           class="rounded-md px-3 py-2 text-center {{ $ehEmpresa ? 'bg-white font-medium shadow-sm' : 'text-slate-600' }}">
            Sou empresa
        </a>
        <a href="{{ route('registro', ['tipo' => 'escritorio']) }}"
           class="rounded-md px-3 py-2 text-center {{ ! $ehEmpresa ? 'bg-white font-medium shadow-sm' : 'text-slate-600' }}">
            Sou escritório contábil
        </a>
    </div>

    <form method="POST" action="{{ route('registro') }}" class="mt-6 space-y-6">
        @csrf
        <input type="hidden" name="tipo" value="{{ $tipoAtual }}">

        @if ($ehEmpresa)
            @include('empresa.partials.formulario-dados', [
                'empresa' => $empresa, 'ufs' => $ufs, 'modo' => 'cadastro',
                'urlCnpj' => route('registro.cnpj', '00000000000000'),
            ])

            {{-- 4. Acesso --}}
            <section class="border-t border-slate-200 pt-6">
                <h2 class="flex items-center gap-2 font-medium"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-marca-600 text-xs text-white">4</span> Seu acesso</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-campo label="Seu nome" name="name" required autocomplete="name" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-campo label="E-mail" name="email" type="email" required autocomplete="username"
                                 ajuda="Usado para entrar no sistema. As notas emitidas também são enviadas para este e-mail." />
                    </div>
                    <x-campo label="Senha" name="password" type="password" required autocomplete="new-password" ajuda="Mínimo de 8 caracteres, com letras e números." />
                    <x-campo label="Confirme a senha" name="password_confirmation" type="password" required autocomplete="new-password" />
                </div>
            </section>
        @else
            <div class="space-y-4">
                <x-campo label="CNPJ" name="cnpj" required inputmode="numeric" placeholder="00.000.000/0000-00" />
                <x-campo label="Razão social do escritório" name="razao_social" required />
                <x-campo label="Seu nome" name="name" required autocomplete="name" />
                <x-campo label="E-mail" name="email" type="email" required autocomplete="username" ajuda="Usado para entrar no sistema." />
                <x-campo label="Senha" name="password" type="password" required autocomplete="new-password" ajuda="Mínimo de 8 caracteres, com letras e números." />
                <x-campo label="Confirme a senha" name="password_confirmation" type="password" required autocomplete="new-password" />
            </div>
        @endif

        <div>
            <label class="inline-flex items-start gap-2 text-sm text-slate-600">
                <input type="checkbox" name="aceite" value="1" class="mt-0.5 rounded border-slate-300" @checked(old('aceite'))>
                <span>
                    Li e aceito os <a href="{{ route('termos') }}" target="_blank" class="text-marca-700 underline">Termos de Uso</a>
                    e a <a href="{{ route('privacidade') }}" target="_blank" class="text-marca-700 underline">Política de Privacidade</a>.
                </span>
            </label>
            @error('aceite')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="w-full rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">
            Criar conta
        </button>
        @if ($ehEmpresa)
            <p class="text-center text-xs text-slate-500">Depois de criar a conta, o próximo passo é enviar o certificado digital A1 da empresa.</p>
        @endif
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Já tem conta?
        <a href="{{ route('login') }}" class="font-medium text-marca-700 hover:underline">Entrar</a>
    </p>

    @unless ($ehEmpresa)
        <script>
            (() => {
                const doc = document.getElementById('cnpj');
                const formatar = () => {
                    doc.value = doc.value.replace(/\D/g, '').slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
                        .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
                };
                doc.addEventListener('input', formatar);
                formatar();
            })();
        </script>
    @endunless
@endsection
