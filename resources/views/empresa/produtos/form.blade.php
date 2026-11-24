@extends('layouts.app')

@php
    use App\Models\Cfop;
    use App\Models\Produto;
    use App\Support\Formata;
    $editando = $produto->exists;
    $preco = $produto->valor_unitario !== null ? number_format((float) $produto->valor_unitario, 2, ',', '.') : '';
    $unidadeAtual = old('unidade', $produto->unidade ?: 'UN');
@endphp

@section('titulo', $editando ? 'Editar produto' : 'Novo produto')

@section('conteudo')
    <div class="max-w-3xl">
        <a href="{{ route('empresa.produtos.index') }}" class="text-sm text-slate-500 hover:text-marca-700">← Produtos</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $editando ? $produto->descricao : 'Novo produto' }}</h1>

        <form method="POST" action="{{ $editando ? route('empresa.produtos.update', $produto->id) : route('empresa.produtos.store') }}" class="mt-6 space-y-6">
            @csrf
            @if ($editando) @method('PUT') @endif

            {{-- Produto --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-medium">Produto</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-700">Código</label>
                        <input type="text" value="{{ $editando ? $produto->codigo : $proximoCodigo }}" disabled
                               class="mt-1 block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-500">
                        <p class="mt-1 text-xs text-slate-500">{{ $editando ? 'Gerado pelo sistema.' : 'Gerado automaticamente ao salvar.' }}</p>
                    </div>
                    <div class="sm:col-span-4">
                        <x-campo label="Descrição" name="descricao" :value="$produto->descricao" required maxlength="120" ajuda="Como vai sair na nota." />
                    </div>

                    <div class="sm:col-span-2">
                        <x-selecao label="Unidade" name="unidade" required>
                            @foreach (Produto::UNIDADES as $sigla => $nome)
                                <option value="{{ $sigla }}" @selected($unidadeAtual === $sigla)>{{ $sigla }} - {{ $nome }}</option>
                            @endforeach
                            @unless (array_key_exists($unidadeAtual, Produto::UNIDADES))
                                <option value="{{ $unidadeAtual }}" selected>{{ $unidadeAtual }} (fora da lista)</option>
                            @endunless
                        </x-selecao>
                    </div>
                    <div class="sm:col-span-2">
                        <x-campo label="Preço de venda (R$)" name="valor_unitario" :value="$preco" required inputmode="numeric" placeholder="0,00" class="text-right tabular-nums" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-campo label="Código de barras (opcional)" name="gtin" :value="$produto->gtin === 'SEM GTIN' ? '' : $produto->gtin" inputmode="numeric" maxlength="14" ajuda="Em branco = SEM GTIN." />
                    </div>
                </div>
            </section>

            {{-- NCM --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-medium">Classificação fiscal (NCM)</h2>
                <p class="mt-1 text-xs text-slate-500">Digite o código de 8 dígitos ou pesquise pela descrição do produto.</p>

                <div class="mt-4">
                    @include('partials.busca-tabela', [
                        'nome' => 'ncm', 'rotulo' => 'NCM', 'valor' => Formata::ncm($produto->ncm),
                        'descricaoAtual' => $ncmAtual?->descricao_completa,
                        'digitos' => 8, 'mascara' => 'ncm', 'placeholder' => '0000.00.00',
                        'urlBusca' => route('empresa.ncm.buscar'), 'urlCodigo' => url('/empresa/ncm'),
                        'disponivel' => $tabelaNcm,
                        'textoBusca' => 'Ex.: camiseta de algodão, parafuso, café torrado',
                        'ajudaBusca' => 'Só aparecem códigos completos de 8 dígitos, os únicos aceitos na nota.',
                        'campoExtra' => ['label' => 'CEST (opcional)', 'name' => 'cest', 'value' => $produto->cest, 'maxlength' => 7,
                                         'ajuda' => 'Só para produtos com substituição tributária.'],
                    ])
                </div>

                @unless ($tabelaNcm)
                    <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        A pesquisa de NCM ainda não está disponível. Digite o código de 8 dígitos.
                    </p>
                @endunless
            </section>

            {{-- Tributação --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-medium">Tributação padrão</h2>
                <p class="mt-1 text-xs text-slate-500">Sugestões para o Simples Nacional. Na emissão você ainda pode ajustar cada nota. Em caso de dúvida, confirme com seu contador.</p>

                <div class="mt-4">
                    @include('partials.busca-tabela', [
                        'nome' => 'cfop_padrao', 'rotulo' => 'CFOP', 'valor' => Cfop::formatar($produto->cfop_padrao),
                        'descricaoAtual' => $cfopAtual?->titulo,
                        'digitos' => 4, 'mascara' => 'cfop', 'placeholder' => '5.102',
                        'urlBusca' => route('empresa.cfop.buscar'), 'urlCodigo' => url('/empresa/cfop'),
                        'abreComSugestoes' => true,
                        'textoBusca' => 'Ex.: substituição tributária, devolução de compra, bonificação',
                        'ajudaBusca' => 'Tabela oficial completa. Só aparecem CFOPs de saída que podem ir na NF-e.',
                    ])
                    <p class="mt-1 text-xs text-slate-500">Use o CFOP de dentro do estado (5.xxx). Para cliente de outro estado, a nota troca para o 6.xxx correspondente.</p>
                </div>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <x-selecao label="Origem da mercadoria" name="origem" required>
                        @foreach (Produto::ORIGENS as $codigo => $nome)
                            <option value="{{ $codigo }}" @selected((int) old('origem', $produto->origem) === $codigo)>{{ $nome }}</option>
                        @endforeach
                    </x-selecao>
                    <x-selecao label="CSOSN (ICMS do Simples Nacional)" name="cst_csosn" required>
                        @foreach (Produto::CSOSN as $codigo => $nome)
                            <option value="{{ $codigo }}" @selected(old('cst_csosn', $produto->cst_csosn) === (string) $codigo)>{{ $nome }}</option>
                        @endforeach
                    </x-selecao>

                    <x-selecao label="CST do PIS" name="cst_pis" required>
                        @foreach (Produto::CST_PIS_COFINS as $codigo => $nome)
                            <option value="{{ $codigo }}" @selected(old('cst_pis', $produto->cst_pis) === (string) $codigo)>{{ $nome }}</option>
                        @endforeach
                    </x-selecao>
                    <x-selecao label="CST da COFINS" name="cst_cofins" required>
                        @foreach (Produto::CST_PIS_COFINS as $codigo => $nome)
                            <option value="{{ $codigo }}" @selected(old('cst_cofins', $produto->cst_cofins) === (string) $codigo)>{{ $nome }}</option>
                        @endforeach
                    </x-selecao>
                </div>

                {{-- Reforma tributária --}}
                @php($classTribAtual = ($c = old('cclass_trib', $produto->cclass_trib)) ? \App\Models\ClassTrib::find($c) : null)
                <details class="mt-6 rounded-xl border border-slate-200 p-4" @if (old('cclass_trib', $produto->cclass_trib) || $errors->hasAny(['cst_ibs_cbs', 'cclass_trib'])) open @endif>
                    <summary class="cursor-pointer text-sm font-medium text-slate-700">IBS e CBS (reforma tributária) - opcional</summary>
                    <p class="mt-2 text-xs text-slate-500">
                        Empresas do Simples Nacional e MEI ainda não precisam informar (previsto a partir de 2027).
                        Se quiser já deixar pronto: para a venda comum de mercadorias use <strong>000001</strong>
                        (tributação integral). Produtos com benefício (cesta básica, saúde, hortifrúti...) têm
                        classificação própria: pesquise pela descrição ou consulte seu contador.
                    </p>
                    <div class="mt-4">
                        @include('partials.busca-tabela', [
                            'nome' => 'cclass_trib', 'rotulo' => 'cClassTrib', 'valor' => $produto->cclass_trib,
                            'descricaoAtual' => $classTribAtual ? $classTribAtual->descricao.' ('.$classTribAtual->resumo().')' : null,
                            'digitos' => 6, 'mascara' => 'classtrib', 'placeholder' => '000001', 'obrigatorio' => false,
                            'urlBusca' => route('empresa.classtrib.buscar'), 'urlCodigo' => url('/empresa/classtrib'),
                            'textoBusca' => 'Ex.: cesta básica, hortícolas, medicamento, exportação',
                            'ajudaBusca' => 'Tabela oficial do Portal da Conformidade Fácil. Só aparecem as permitidas na NF-e.',
                            'abreComSugestoes' => true,
                        ])
                    </div>
                    <div class="mt-4 max-w-xs">
                        <x-campo label="CST do IBS/CBS" name="cst_ibs_cbs" :value="$produto->cst_ibs_cbs" readonly tabindex="-1"
                                 class="bg-slate-50" ajuda="Preenchido sozinho: são os 3 primeiros dígitos da classificação." />
                    </div>
                </details>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    @if ($editando)
                        <button type="submit" form="excluir" class="text-sm text-red-700 hover:underline">Excluir produto</button>
                    @endif
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('empresa.produtos.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700 hover:bg-slate-100">Cancelar</a>
                    <button type="submit" class="rounded-lg bg-marca-600 px-5 py-2.5 font-medium text-white hover:bg-marca-700">Salvar</button>
                </div>
            </div>
        </form>

        @if ($editando)
            <form id="excluir" method="POST" action="{{ route('empresa.produtos.destroy', $produto->id) }}"
                  onsubmit="return confirm('Excluir este produto? As notas já emitidas não são afetadas.')">
                @csrf
                @method('DELETE')
            </form>
        @endif
    </div>

    <script>
        (() => {
            // Preço: os números entram da direita para a esquerda (digite 1890 para R$ 18,90)
            const preco = document.getElementById('valor_unitario');
            const formatarPreco = () => {
                const centavos = preco.value.replace(/\D/g, '').replace(/^0+/, '').slice(0, 13);
                if (!centavos) { preco.value = ''; return; }
                const valor = (Number(centavos) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                preco.value = valor;
            };
            preco.addEventListener('input', formatarPreco);
            preco.addEventListener('focus', () => setTimeout(() => preco.setSelectionRange(preco.value.length, preco.value.length)));

            // Campos só de números
            ['gtin', 'cest'].forEach((id) => {
                const el = document.getElementById(id);
                el.addEventListener('input', () => { el.value = el.value.replace(/\D/g, ''); });
            });

            // CST do IBS/CBS acompanha a classificação escolhida
            const classTrib = document.getElementById('cclass_trib');
            const cstIbs = document.getElementById('cst_ibs_cbs');
            const atualizarCst = () => {
                const n = classTrib.value.replace(/\D/g, '');
                cstIbs.value = n.length === 6 ? n.slice(0, 3) : '';
            };
            ['input', 'change'].forEach((ev) => classTrib.addEventListener(ev, atualizarCst));
        })();
    </script>
@endsection
