@extends('layouts.app')

@section('titulo', 'Certificado digital')

@section('conteudo')
    <div class="max-w-2xl">
        <h1 class="text-2xl font-semibold">Certificado digital A1</h1>
        <p class="mt-1 text-sm text-slate-500">Usado para assinar as notas da empresa. Fica guardado criptografado.</p>

        @if ($certificado)
            @php($dias = $certificado->venceEmDias())
            <section class="mt-6 rounded-2xl border bg-white p-5 shadow-sm sm:p-6 {{ $dias !== null && $dias <= 30 ? 'border-amber-300' : 'border-slate-200' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-medium">Certificado atual</h2>
                        <p class="mt-2 text-sm text-slate-700">{{ $certificado->titular }}</p>
                        <p class="text-sm text-slate-500">CNPJ {{ \App\Support\Formata::cnpj($certificado->cnpj_certificado) }}</p>
                    </div>
                    @if ($dias !== null && $dias < 0)
                        <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-800">Vencido</span>
                    @elseif ($dias !== null && $dias <= 30)
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Vence em {{ $dias }} {{ $dias === 1 ? 'dia' : 'dias' }}</span>
                    @else
                        <span class="rounded-full bg-marca-100 px-3 py-1 text-xs font-medium text-marca-900">Válido</span>
                    @endif
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-slate-500">Válido desde</dt>
                        <dd>{{ $certificado->valido_de?->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Válido até</dt>
                        <dd>{{ $certificado->valido_ate?->format('d/m/Y') }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('empresa.certificado.destroy') }}" class="mt-4"
                      onsubmit="return confirm('Remover o certificado? Sem ele não é possível emitir notas.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-sm text-red-700 hover:underline">Remover certificado</button>
                </form>
            </section>
        @endif

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="font-medium">{{ $certificado ? 'Substituir certificado' : 'Enviar certificado' }}</h2>
            <p class="mt-1 text-xs text-slate-500">
                Arquivo .pfx ou .p12 do certificado e-CNPJ tipo A1 da empresa {{ \App\Support\Formata::cnpj($empresa->cnpj) }}.
                O certificado da matriz também serve para as filiais.
            </p>

            <form method="POST" action="{{ route('empresa.certificado.store') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf

                <div>
                    <label for="arquivo" class="block text-sm font-medium text-slate-700">Arquivo do certificado</label>
                    <input id="arquivo" name="arquivo" type="file" accept=".pfx,.p12" required
                           class="mt-1 block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-slate-700 hover:file:bg-slate-200">
                    @error('arquivo')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <x-campo label="Senha do certificado" name="senha" type="password" required autocomplete="off" />

                <button type="submit" class="rounded-lg bg-marca-600 px-5 py-2.5 font-medium text-white hover:bg-marca-700">
                    Enviar certificado
                </button>
            </form>
        </section>
    </div>
@endsection
