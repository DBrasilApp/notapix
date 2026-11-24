@extends('layouts.guest')

@section('titulo', 'Entrar')

@section('conteudo')
    <h1 class="text-xl font-semibold">Entrar</h1>
    <p class="mt-1 text-sm text-slate-500">Acesse sua conta para emitir suas notas.</p>

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf

        <x-campo label="E-mail" name="email" type="email" required autofocus autocomplete="username" />
        <x-campo label="Senha" name="password" type="password" required autocomplete="current-password" />

        <div class="flex items-center justify-between text-sm">
            <label class="inline-flex items-center gap-2 text-slate-600">
                <input type="checkbox" name="lembrar" class="rounded border-slate-300">
                Manter conectado
            </label>
            <a href="{{ route('password.request') }}" class="text-marca-700 hover:underline">Esqueci a senha</a>
        </div>

        <button type="submit" class="w-full rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">
            Entrar
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Ainda não tem conta?
        <a href="{{ route('registro') }}" class="font-medium text-marca-700 hover:underline">Cadastre-se grátis</a>
    </p>
@endsection
