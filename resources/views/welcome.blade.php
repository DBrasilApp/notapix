<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · Emita NF-e pagando só pelo que usar</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white font-sans text-slate-900 antialiased">
    <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5">
        <x-logo />
        <nav class="flex items-center gap-3 text-sm">
            @auth
                <a href="{{ route('painel') }}" class="rounded-lg bg-marca-600 px-4 py-2 font-medium text-white hover:bg-marca-700">Meu painel</a>
            @else
                <a href="{{ route('login') }}" class="font-medium text-slate-700 hover:text-marca-700">Entrar</a>
                <a href="{{ route('registro') }}" class="rounded-lg bg-marca-600 px-4 py-2 font-medium text-white hover:bg-marca-700">Criar conta</a>
            @endauth
        </nav>
    </header>

    {{-- Página inicial provisória: a página de vendas será feita na etapa de divulgação --}}
    <main class="mx-auto max-w-3xl px-4 py-20 text-center">
        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Emita só quando precisar.</h1>
        <p class="mt-4 text-lg text-slate-600">Sem mensalidade, sem contrato. Você compra créditos por Pix e paga apenas pelas notas fiscais que emitir.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('registro', ['tipo' => 'empresa']) }}" class="rounded-lg bg-marca-600 px-5 py-3 font-medium text-white hover:bg-marca-700">Sou empresa</a>
            <a href="{{ route('registro', ['tipo' => 'escritorio']) }}" class="rounded-lg border border-slate-300 px-5 py-3 font-medium text-slate-700 hover:bg-slate-50">Sou escritório contábil</a>
        </div>
    </main>
</body>
</html>
