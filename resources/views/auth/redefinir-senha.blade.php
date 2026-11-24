@extends('layouts.guest')

@section('titulo', 'Nova senha')

@section('conteudo')
    <h1 class="text-xl font-semibold">Criar nova senha</h1>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-campo label="E-mail" name="email" type="email" :value="$email" required />
        <x-campo label="Nova senha" name="password" type="password" required autocomplete="new-password" />
        <x-campo label="Confirme a nova senha" name="password_confirmation" type="password" required autocomplete="new-password" />

        <button type="submit" class="w-full rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">
            Salvar nova senha
        </button>
    </form>
@endsection
