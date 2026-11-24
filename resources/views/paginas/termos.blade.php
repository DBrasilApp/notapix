@extends('layouts.guest')

@section('titulo', 'Termos de Uso')

@section('conteudo')
    <h1 class="text-xl font-semibold">Termos de Uso</h1>
    <p class="mt-1 text-xs text-slate-500">Versão {{ config('nfeexpress.termos_versao') }}</p>

    <div class="mt-4 space-y-3 text-sm text-slate-700">
        {{-- TODO: substituir pelo texto definitivo dos Termos de Uso (em redação) --}}
        <p>O texto completo dos Termos de Uso está em redação e será publicado antes do lançamento.</p>
    </div>
@endsection
