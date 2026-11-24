@extends('layouts.guest')

@section('titulo', 'Política de Privacidade')

@section('conteudo')
    <h1 class="text-xl font-semibold">Política de Privacidade</h1>
    <p class="mt-1 text-xs text-slate-500">Versão {{ config('nfeexpress.privacidade_versao') }}</p>

    <div class="mt-4 space-y-3 text-sm text-slate-700">
        {{-- TODO: substituir pelo texto definitivo da Política de Privacidade (em redação) --}}
        <p>O texto completo da Política de Privacidade está em redação e será publicado antes do lançamento.</p>
    </div>
@endsection
