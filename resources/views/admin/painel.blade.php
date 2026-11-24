@extends('layouts.app')

@section('titulo', 'Administração')

@section('conteudo')
    <h1 class="text-2xl font-semibold">Administração</h1>
    <p class="mt-1 text-sm text-slate-500">Visão geral do sistema.</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-cartao titulo="Empresas" :valor="$totalEmpresas" />
        <x-cartao titulo="Escritórios contábeis" :valor="$totalEscritorios" />
        <x-cartao titulo="Usuários" :valor="$totalUsuarios" />
        <x-cartao titulo="Notas autorizadas" :valor="$notasAutorizadas" />
    </div>

    <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
        <h2 class="border-b border-slate-200 px-5 py-3 font-medium">Últimos cadastros</h2>

        @if ($ultimosCadastros->isEmpty())
            <p class="px-5 py-6 text-sm text-slate-500">Nenhum cadastro ainda.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Usuário</th>
                            <th class="px-5 py-2 font-medium">Perfil</th>
                            <th class="px-5 py-2 font-medium">Vinculado a</th>
                            <th class="px-5 py-2 font-medium">Cadastro</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($ultimosCadastros as $u)
                            <tr>
                                <td class="px-5 py-2">
                                    <div class="font-medium">{{ $u->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $u->email }}</div>
                                </td>
                                <td class="px-5 py-2">{{ $u->perfil->label() }}</td>
                                <td class="px-5 py-2">{{ $u->empresa?->razao_social ?? $u->escritorio?->razao_social ?? '-' }}</td>
                                <td class="px-5 py-2 text-slate-500">{{ $u->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
