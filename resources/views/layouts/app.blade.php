<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo', 'Painel') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    @php($usuario = auth()->user())
    {{-- Área da empresa: a própria empresa ou o escritório trabalhando numa empresa-cliente --}}
    @php($emEmpresa = $usuario->isEmpresa() || (isset($acessoEscritorio) && request()->routeIs('empresa.*')))
    @php($painelUrl = $emEmpresa ? route('empresa.painel') : route('painel'))

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
            <div class="flex items-center gap-6">
                <x-logo />
                <nav class="hidden gap-4 text-sm sm:flex">
                    @php($link = fn ($rota) => request()->routeIs($rota) ? 'font-medium text-marca-700' : 'font-medium text-slate-700 hover:text-marca-700')
                    <a href="{{ $painelUrl }}" class="{{ $link('*.painel') }}">Painel</a>
                    @if ($emEmpresa)
                        <a href="{{ route('empresa.notas.index') }}" class="{{ $link('empresa.notas.*') }}">Notas</a>
                        <a href="{{ route('empresa.nfce.create') }}" class="{{ $link('empresa.nfce.*') }}">Venda rápida</a>
                        {{-- Serviços (NFS-e): menu separado, para não misturar com as notas de produtos --}}
                        <details class="relative" data-menu>
                            <summary class="cursor-pointer list-none {{ $link('empresa.nfse.*') }} {{ request()->routeIs('empresa.servicos.*') ? 'text-marca-700' : '' }}">Serviços ▾</summary>
                            <div class="absolute left-0 z-20 mt-2 w-56 rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                                <a href="{{ route('empresa.nfse.create') }}" class="block px-4 py-2 text-slate-700 hover:bg-slate-50">Emitir NFS-e</a>
                                <a href="{{ route('empresa.nfse.lote') }}" class="block px-4 py-2 text-slate-700 hover:bg-slate-50">Emissão em lote</a>
                                <a href="{{ route('empresa.nfse.clientes.index') }}" class="block px-4 py-2 text-slate-700 hover:bg-slate-50">Clientes mensais</a>
                                <a href="{{ route('empresa.nfse.index') }}" class="block px-4 py-2 text-slate-700 hover:bg-slate-50">NFS-e emitidas</a>
                                <div class="my-1 border-t border-slate-100"></div>
                                <a href="{{ route('empresa.servicos.index') }}" class="block px-4 py-2 text-slate-700 hover:bg-slate-50">Cadastro de serviços</a>
                            </div>
                        </details>
                        <a href="{{ route('empresa.pessoas.index') }}" class="{{ $link('empresa.pessoas.*') }}">Pessoas</a>
                        <a href="{{ route('empresa.produtos.index') }}" class="{{ $link('empresa.produtos.*') }}">Produtos</a>
                        <a href="{{ route('empresa.dados') }}" class="{{ $link('empresa.dados*') }}">Dados da empresa</a>
                        <a href="{{ route('empresa.certificado') }}" class="{{ $link('empresa.certificado*') }}">Certificado</a>
                        @if ($usuario->isEmpresa())
                            <a href="{{ route('empresa.contador') }}" class="{{ $link('empresa.contador*') }}">Contador</a>
                        @endif
                    @elseif ($usuario->isEscritorio())
                        <a href="{{ route('escritorio.empresas.create') }}" class="{{ $link('escritorio.empresas.create') }}">Adicionar empresa</a>
                        <a href="{{ route('escritorio.arquivos') }}" class="{{ $link('escritorio.arquivos') }}">XML do mês</a>
                    @endif
                    {{-- Novos itens de menu por perfil entram aqui (notas, cadastros, créditos...) --}}
                </nav>
            </div>

            <div class="flex items-center gap-3 text-sm">
                <div class="text-right leading-tight">
                    <div class="font-medium">{{ $usuario->name }}</div>
                    <div class="text-xs text-slate-500">{{ $usuario->perfil->label() }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-100">Sair</button>
                </form>
            </div>
        </div>
    </header>

    @if (isset($acessoEscritorio) && $emEmpresa)
        <div class="flex flex-wrap items-center justify-center gap-3 bg-sky-700 px-4 py-2 text-sm text-white">
            <span>Você está trabalhando na empresa <strong>{{ $usuario->empresa->razao_social }}</strong> como {{ $acessoEscritorio->razao_social }}.</span>
            <form method="POST" action="{{ route('escritorio.empresas.sair') }}">
                @csrf
                <button class="rounded-md bg-white/15 px-3 py-1 font-medium hover:bg-white/25">Voltar ao escritório</button>
            </form>
        </div>
    @endif

    @if ($emEmpresa && $usuario->empresa?->emHomologacao())
        <div class="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900">
            Ambiente de <strong>homologação</strong>: as notas emitidas aqui não têm valor fiscal.
        </div>
    @endif

    {{-- Menu no celular --}}
    @if ($emEmpresa)
        <nav class="flex gap-4 overflow-x-auto border-b border-slate-200 bg-white px-4 py-2 text-sm sm:hidden">
            <a href="{{ $painelUrl }}" class="whitespace-nowrap text-slate-700">Painel</a>
            <a href="{{ route('empresa.notas.index') }}" class="whitespace-nowrap text-slate-700">Notas</a>
            <a href="{{ route('empresa.nfce.create') }}" class="whitespace-nowrap text-slate-700">Venda rápida</a>
            <a href="{{ route('empresa.nfse.create') }}" class="whitespace-nowrap text-slate-700">Serviços (NFS-e)</a>
            <a href="{{ route('empresa.pessoas.index') }}" class="whitespace-nowrap text-slate-700">Pessoas</a>
            <a href="{{ route('empresa.produtos.index') }}" class="whitespace-nowrap text-slate-700">Produtos</a>
            <a href="{{ route('empresa.dados') }}" class="whitespace-nowrap text-slate-700">Dados da empresa</a>
            <a href="{{ route('empresa.certificado') }}" class="whitespace-nowrap text-slate-700">Certificado</a>
            @if ($usuario->isEmpresa())<a href="{{ route('empresa.contador') }}" class="whitespace-nowrap text-slate-700">Contador</a>@endif
        </nav>
    @endif

    <script>
        // Fecha o menu "Serviços" ao clicar fora dele
        document.addEventListener('click', (e) => {
            document.querySelectorAll('details[data-menu][open]').forEach((m) => { if (!m.contains(e.target)) m.removeAttribute('open'); });
        });
    </script>

    <main class="mx-auto max-w-6xl px-4 py-8">
        <x-alertas />
        @yield('conteudo')
    </main>
</body>
</html>
