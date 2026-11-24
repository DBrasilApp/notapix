{{-- Navegação do módulo Serviços (NFS-e) --}}
@php
    $abas = [
        'emitir' => ['Emitir NFS-e', route('empresa.nfse.create')],
        'lote' => ['Emissão em lote', route('empresa.nfse.lote')],
        'clientes' => ['Clientes mensais', route('empresa.nfse.clientes.index')],
        'emitidas' => ['NFS-e emitidas', route('empresa.nfse.index')],
        'servicos' => ['Cadastro de serviços', route('empresa.servicos.index')],
    ];
@endphp
<nav class="-mx-4 flex gap-1 overflow-x-auto px-4 text-sm sm:mx-0 sm:px-0">
    <span class="mr-2 self-center whitespace-nowrap text-xs font-medium uppercase tracking-wide text-slate-400">Serviços</span>
    @foreach ($abas as $chave => [$rotulo, $url])
        <a href="{{ $url }}"
           class="whitespace-nowrap rounded-lg px-3 py-1.5 {{ $aba === $chave ? 'bg-marca-600 font-medium text-white' : 'text-slate-600 hover:bg-slate-100' }}">{{ $rotulo }}</a>
    @endforeach
</nav>
