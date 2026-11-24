@extends('layouts.app')

@php
    use App\Models\Nfse;
    use App\Support\Formata;
    use App\Support\TabelaServicosNacional;
    $editando = $nfse->exists;
    $dinheiro = fn ($v) => $v !== null && (float) $v > 0 ? number_format((float) $v, 2, ',', '.') : '';
    $temRetencoes = collect(array_keys(Nfse::RETENCOES))->contains(fn ($c) => (float) old($c, $nfse->{$c}) > 0);
    $mei = $empresa->ehMei();
    $aliquotaIss = (float) $empresa->nfse_aliquota_iss;
    $servicosJs = $servicos->mapWithKeys(fn ($s) => [$s->id => [
        'discriminacao' => $s->discriminacao, 'valor' => $s->valor !== null ? number_format((float) $s->valor, 2, ',', '.') : '',
        'iss_retido' => $s->iss_retido, 'codigo' => TabelaServicosNacional::formatar($s->ctribnac).($s->ctribmun ? '.'.$s->ctribmun : '').' - '.TabelaServicosNacional::descricao($s->ctribnac),
    ]]);
    $tomadoresJs = $tomadores->mapWithKeys(fn ($t) => [$t->id => [
        'endereco' => \App\Services\Nfse\MontadorDps::enderecoCompleto($t->only(['cep', 'logradouro', 'bairro', 'codigo_municipio'])),
    ]]);
@endphp

@section('titulo', $editando ? 'Corrigir NFS-e' : 'Emitir NFS-e')

@section('conteudo')
    @include('empresa.nfse.partials.abas', ['aba' => 'emitir'])

    <div class="mt-6 max-w-4xl">
        <h1 class="text-2xl font-semibold">{{ $editando ? 'Corrigir e enviar de novo' : 'Emitir NFS-e' }}</h1>
        <p class="mt-1 text-sm text-slate-500">Nota fiscal de serviço do padrão nacional. A nota é gerada na hora pela SEFIN Nacional.</p>

        @if ($pendencias)
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-medium">Antes de emitir, falta:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($pendencias as $chave => $texto)
                        <li>
                            @if (in_array($chave, ['dados', 'nfse'], true))<a class="underline" href="{{ route('empresa.dados') }}#nfse">{{ $texto }}</a>
                            @elseif ($chave === 'certificado')<a class="underline" href="{{ route('empresa.certificado') }}">{{ $texto }}</a>
                            @else {{ $texto }} @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @error('emissao')
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-medium">A nota não foi enviada:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ((array) $errors->get('emissao') as $msgs)
                        @foreach ((array) $msgs as $m)<li>{{ $m }}</li>@endforeach
                    @endforeach
                </ul>
            </div>
        @enderror

        @if ($servicos->isEmpty())
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                <p class="font-medium">Cadastre primeiro o serviço que você presta.</p>
                <p class="mt-1 text-sm text-slate-500">Uma vez só: o código da lista nacional e o texto padrão da nota.</p>
                <a href="{{ route('empresa.servicos.create', ['voltar' => 'emitir']) }}" class="mt-4 inline-block rounded-lg bg-marca-600 px-4 py-2.5 font-medium text-white hover:bg-marca-700">Cadastrar serviço</a>
            </div>
        @else
        <form method="POST" action="{{ $editando ? route('empresa.nfse.update', $nfse->id) : route('empresa.nfse.store') }}" class="mt-6 grid gap-6 lg:grid-cols-3" id="form-nfse">
            @csrf
            @if ($editando) @method('PUT') @endif

            <div class="space-y-6 lg:col-span-2">
                {{-- Tomador --}}
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="font-medium">Tomador (cliente)</h2>
                        <a href="{{ route('empresa.pessoas.create') }}" class="text-sm text-marca-700 hover:underline" target="_blank">+ Nova pessoa</a>
                    </div>
                    <div class="mt-3">
                        @php($tomadorAtual = (string) old('destinatario_id', $nfse->exists ? ($nfse->destinatario_id ?? '0') : ($nfse->destinatario_id ?? '')))
                        <x-selecao label="Para quem foi o serviço" name="destinatario_id" required>
                            <option value="" disabled @selected($tomadorAtual === '')>Escolha o cliente...</option>
                            <option value="0" @selected($tomadorAtual === '0')>Sem tomador identificado (consumidor que não informou CPF)</option>
                            @foreach ($tomadores as $t)
                                <option value="{{ $t->id }}" @selected($tomadorAtual === (string) $t->id)>
                                    {{ $t->razao_social }}{{ $t->nome_fantasia ? ' ('.$t->nome_fantasia.')' : '' }} - {{ $t->documentoFormatado() }}
                                </option>
                            @endforeach
                        </x-selecao>
                        <p id="aviso-endereco" class="mt-1 hidden text-xs text-amber-700">Endereço do tomador incompleto: com ISS retido, a nota será recusada. Complete o cadastro em Pessoas.</p>
                    </div>
                </section>

                {{-- Serviço --}}
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="font-medium">Serviço prestado</h2>
                        <a href="{{ route('empresa.servicos.create', ['voltar' => 'emitir']) }}" class="text-sm text-marca-700 hover:underline">+ Novo serviço</a>
                    </div>
                    <div class="mt-3 grid gap-4 sm:grid-cols-6">
                        <div class="sm:col-span-4">
                            <x-selecao label="Serviço" name="servico_id" required>
                                <option value="">Escolha...</option>
                                @foreach ($servicos as $s)
                                    <option value="{{ $s->id }}" @selected((int) old('servico_id', $nfse->servico_id) === $s->id)>{{ $s->nome }}</option>
                                @endforeach
                            </x-selecao>
                            <p id="codigo-servico" class="mt-1 text-xs text-slate-500"></p>
                        </div>
                        <div class="sm:col-span-2">
                            <x-campo label="Competência (mês)" name="competencia" type="month" required
                                     :value="old('competencia', $nfse->competencia?->format('Y-m') ?? now()->format('Y-m'))" :max="now()->format('Y-m')"
                                     ajuda="Mês em que o serviço foi prestado." />
                        </div>
                        <div class="sm:col-span-6">
                            <label for="discriminacao" class="block text-sm font-medium text-slate-700">Descrição do serviço (vai na nota)</label>
                            <textarea id="discriminacao" name="discriminacao" rows="5" maxlength="2000" required
                                      class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">{{ old('discriminacao', $nfse->discriminacao) }}</textarea>
                            <p class="mt-1 text-xs text-slate-500"><span class="font-mono">{mes}</span> vira o mês da competência.</p>
                            @error('discriminacao')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                {{-- Valores e retenções --}}
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <h2 class="font-medium">Valor e impostos</h2>
                    <div class="mt-3 grid gap-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                            <x-campo label="Valor do serviço (R$)" name="valor_servico" :value="$dinheiro($nfse->valor_servico)" required
                                     inputmode="numeric" placeholder="0,00" class="text-right text-lg tabular-nums" data-dinheiro />
                        </div>
                        <div class="sm:col-span-4 flex items-end">
                            <label class="flex items-start gap-2 text-sm text-slate-700 {{ $mei ? 'opacity-50' : '' }}">
                                <input type="checkbox" name="iss_retido" id="iss_retido" value="1" class="mt-0.5 rounded border-slate-300"
                                       @checked(old('iss_retido', $nfse->iss_retido)) @disabled($mei)>
                                <span>ISS retido pelo tomador
                                    <span class="block text-xs text-slate-500">
                                        @if ($mei) MEI não sofre retenção de ISS.
                                        @elseif ($aliquotaIss > 0) O cliente desconta {{ number_format($aliquotaIss, 2, ',', '.') }}% de ISS e paga à prefeitura.
                                        @else Informe a alíquota do ISS em <a href="{{ route('empresa.dados') }}#nfse" class="underline">Dados da empresa → NFS-e</a>.
                                        @endif
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    @unless ($mei)
                        <details class="mt-5 rounded-xl border border-slate-200 p-4" @if ($temRetencoes || $errors->hasAny(array_keys(Nfse::RETENCOES))) open @endif>
                            <summary class="cursor-pointer text-sm font-medium text-slate-700">Retenções federais (INSS, IRRF, CSLL, PIS, COFINS) - só se o cliente descontar</summary>
                            <div class="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
                                Empresa do <strong>Simples Nacional</strong> normalmente <strong>não</strong> sofre retenção de IRRF, CSLL, PIS e COFINS
                                (o Simples já recolhe tudo no DAS). A retenção de <strong>INSS (11%)</strong> acontece em cessão de mão de obra/empreitada
                                (ex.: limpeza, vigilância, obras). Na dúvida, pergunte ao seu contador.
                            </div>
                            <div class="mt-3 grid gap-4 sm:grid-cols-5">
                                @foreach (['ret_inss' => ['INSS', 11], 'ret_irrf' => ['IRRF', 1.5], 'ret_csll' => ['CSLL', 1], 'ret_pis' => ['PIS', 0.65], 'ret_cofins' => ['COFINS', 3]] as $campo => [$rotulo, $pct])
                                    <div>
                                        <x-campo :label="$rotulo.' (R$)'" :name="$campo" :value="$dinheiro($nfse->{$campo})" inputmode="numeric" placeholder="0,00"
                                                 class="text-right tabular-nums" data-dinheiro data-retencao />
                                        <button type="button" class="mt-1 text-xs text-marca-700 hover:underline" data-calcular="{{ $campo }}" data-pct="{{ $pct }}">
                                            usar {{ str_replace('.', ',', $pct) }}%
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endunless
                </section>

                <details class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" @if (old('informacoes_complementares', $nfse->informacoes_complementares)) open @endif>
                    <summary class="cursor-pointer font-medium">Informações complementares (opcional)</summary>
                    <textarea name="informacoes_complementares" rows="3" maxlength="1500" placeholder="Ex.: número do contrato, pedido ou dados bancários para pagamento"
                              class="mt-3 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">{{ old('informacoes_complementares', $nfse->informacoes_complementares) }}</textarea>
                </details>
            </div>

            {{-- Resumo --}}
            <aside class="lg:col-span-1">
                <div class="sticky top-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-medium">Resumo</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Valor do serviço</dt><dd class="tabular-nums" id="r-valor">0,00</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">ISS retido</dt><dd class="tabular-nums" id="r-iss">0,00</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Retenções federais</dt><dd class="tabular-nums" id="r-fed">0,00</dd></div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><dt>Você recebe</dt><dd class="tabular-nums" id="r-liquido">0,00</dd></div>
                    </dl>
                    @unless ($mei)
                        <p class="mt-3 text-xs text-slate-500">
                            Tributos aproximados: {{ $empresa->nfse_aliquota_simples !== null ? number_format((float) $empresa->nfse_aliquota_simples, 2, ',', '.').'%' : 'não informado' }}
                            (alíquota do Simples, informada em Dados da empresa).
                        </p>
                    @endunless
                    <button type="submit" class="mt-5 w-full rounded-lg bg-marca-600 px-5 py-3 font-medium text-white hover:bg-marca-700 disabled:opacity-60" id="enviar">
                        {{ $editando ? 'Enviar de novo' : 'Emitir NFS-e' }}
                    </button>
                    @if ($empresa->emHomologacao())
                        <p class="mt-2 text-center text-xs text-amber-700">Homologação: a nota não tem valor fiscal.</p>
                    @endif
                    <p class="mt-2 text-center text-xs text-slate-500">Usa 1 crédito se for autorizada.</p>
                </div>
            </aside>
        </form>
        @endif
    </div>

    @include('partials.dinheiro-js')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('form-nfse');
            if (!form) return;
            const servicos = @json($servicosJs);
            const tomadores = @json($tomadoresJs);
            const aliquotaIss = {{ $aliquotaIss }};
            const $ = (id) => document.getElementById(id);
            const valor = $('valor_servico'), servico = $('servico_id'), retido = $('iss_retido'), tomador = $('destinatario_id');

            const recalcular = () => {
                const v = lerDinheiro(valor);
                const iss = retido && retido.checked ? Math.round(v * aliquotaIss) / 100 : 0;
                let fed = 0;
                document.querySelectorAll('[data-retencao]').forEach((el) => { fed += lerDinheiro(el); });
                $('r-valor').textContent = mostrarDinheiro(v);
                $('r-iss').textContent = mostrarDinheiro(iss);
                $('r-fed').textContent = mostrarDinheiro(fed);
                $('r-liquido').textContent = mostrarDinheiro(v - iss - fed);
                const t = tomadores[tomador.value];
                $('aviso-endereco').classList.toggle('hidden', !(retido && retido.checked && t && !t.endereco));
            };

            const mostrarCodigo = () => { $('codigo-servico').textContent = servicos[servico.value]?.codigo || ''; };

            servico.addEventListener('change', () => {
                const s = servicos[servico.value];
                mostrarCodigo();
                if (!s) return;
                $('discriminacao').value = s.discriminacao;
                if (s.valor) valor.value = s.valor;
                if (retido && !retido.disabled) retido.checked = !!s.iss_retido;
                recalcular();
            });

            document.querySelectorAll('[data-calcular]').forEach((b) => b.addEventListener('click', () => {
                const alvo = $(b.dataset.calcular);
                alvo.value = mostrarDinheiro(lerDinheiro(valor) * Number(b.dataset.pct) / 100);
                recalcular();
            }));

            form.addEventListener('input', recalcular);
            form.addEventListener('change', recalcular);
            form.addEventListener('submit', () => { $('enviar').disabled = true; $('enviar').textContent = 'Enviando...'; });
            mostrarCodigo();
            recalcular();
        });
    </script>
@endsection
