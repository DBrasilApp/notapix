<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo', 'Entrar') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen @yield('largura', 'max-w-md') flex-col justify-center px-4 py-10">
        <div class="mb-8 text-center">
            <x-logo />
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <x-alertas />
            @yield('conteudo')
        </div>

        <p class="mt-6 text-center text-xs text-slate-500">
            <a href="{{ route('termos') }}" class="hover:underline">Termos de Uso</a>
            ·
            <a href="{{ route('privacidade') }}" class="hover:underline">Política de Privacidade</a>
        </p>
    </main>
</body>
</html>
