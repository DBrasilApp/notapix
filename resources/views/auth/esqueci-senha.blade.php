@extends('layouts.guest')

@section('titulo', 'Esqueci a senha')

@section('conteudo')
    <h1 class="text-xl font-semibold">Esqueci a senha</h1>
    <p class="mt-1 text-sm text-slate-500">Informe seu e-mail e enviaremos um link para criar uma nova senha.</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <x-campo label="E-mail" name="email" type="email" required autofocus />

        <button type="submit" class="w-full rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">
            Enviar link
        </button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="text-marca-700 hover:underline">Voltar para o login</a>
    </p>
@endsection
