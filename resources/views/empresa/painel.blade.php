@extends('layouts.app')

@section('titulo', 'Painel')

@section('conteudo')
    <h1 class="text-2xl font-semibold">{{ $empresa->razao_social }}</h1>
    <p class="mt-1 text-sm text-slate-500">CNPJ {{ \App\Support\Formata::cnpj($empresa->cnpj) }}</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <x-cartao titulo="Créditos disponíveis" :valor="$saldo" detalhe="1 crédito = 1 nota autorizada" />
        <x-cartao titulo="Notas autorizadas no mês" :valor="$notasNoMes" />
        <x-cartao
            titulo="Certificado digital A1"
            :valor="$empresa->temCertificadoValido() ? 'Válido' : ($certificado ? 'Vencido' : 'Pendente')"
            :detalhe="$certificado?->valido_ate ? 'Válido até '.$certificado->valido_ate->format('d/m/Y') : 'Envie seu certificado para começar a emitir.'"
        />
    </div>

    @if ($pendencias)
        @php
            $passos = [
                'dados' => ['Completar os dados da empresa', 'Endereço, inscrição estadual e regime tributário.', route('empresa.dados')],
                'certificado' => ['Enviar o certificado digital A1', 'Arquivo .pfx ou .p12 com a senha.', route('empresa.certificado')],
                'creditos' => ['Adquirir créditos', 'A compra por Pix entra na reta final do projeto. Para testar, o administrador pode lançar créditos de cortesia.', null],
            ];
        @endphp

        <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="font-medium">Para emitir sua primeira nota</h2>

            <ol class="mt-4 space-y-3">
                @foreach ($passos as $chave => [$titulo, $descricao, $url])
                    @php($feito = ! array_key_exists($chave, $pendencias))
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold {{ $feito ? 'bg-marca-600 text-white' : 'border border-slate-300 text-slate-500' }}">
                            {{ $feito ? '✓' : $loop->iteration }}
                        </span>
                        <div class="text-sm">
                            @if (! $feito && $url)
                                <a href="{{ $url }}" class="font-medium text-marca-700 hover:underline">{{ $titulo }}</a>
                            @else
                                <span class="font-medium {{ $feito ? 'text-slate-400 line-through' : 'text-slate-700' }}">{{ $titulo }}</span>
                            @endif
                            @unless ($feito)
                                <p class="text-slate-500">{{ $descricao }}</p>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @else
        <section class="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-marca-100 bg-marca-50 p-5 text-sm text-marca-900">
            <span>Tudo pronto para emitir.</span>
            <a href="{{ route('empresa.notas.create') }}" class="rounded-lg bg-marca-600 px-4 py-2 font-medium text-white hover:bg-marca-700">Emitir nota</a>
        </section>
    @endif
@endsection
