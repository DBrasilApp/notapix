@extends('layouts.app')

@php
    use App\Models\Destinatario;
    use App\Models\Nota;
    $editando = $nota->exists;
    $operacaoAtual = old('operacao', $nota->operacao ?? 'venda');
    $pessoaAtual = (int) old('destinatario_id', $nota->destinatario_id);
    $formaAtual = old('forma_pagamento', $nota->forma_pagamento ?? '01');
    if ($formaAtual === '90' && $operacaoAtual === 'venda') { $formaAtual = '01'; }
    $pessoasJs = $pessoas->mapWithKeys(fn ($p) => [$p->id => [
        'uf' => $p->uf,
        'pf' => $p->isFisica(),
        'doc' => $p->cpf_cnpj,
        'contribuinte' => $p->ind_ie_dest !== Destinatario::IE_NAO_CONTRIBUINTE,
    ]]);
    $operacoesJs = collect(Nota::OPERACOES)->map(fn ($o) => ['natureza' => $o['natureza'], 'cfop' => $o['cfop'], 'pagamento' => $o['pagamento']]);
    $caixa = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6';
    $entrada = 'mt-1 block w-full rounded-lg border border-slate-300 px-2 py-2 text-sm';
@endphp

@section('titulo', $editando ? 'Editar nota' : 'Nova nota')

@section('conteudo')
    <div class="max-w-5xl">
        <a href="{{ route('empresa.notas.index') }}" class="text-sm text-slate-500 hover:text-marca-700">← Notas fiscais</a>
        <h1 class="mt-2 text-2xl font-semibold">
            {{ $editando ? ($nota->numero ? 'NF-e '.$nota->numeroFormatado() : 'Rascunho') : 'Nova nota fiscal' }}
        </h1>
        @if ($empresa->emHomologacao())
            <p class="mt-1 text-sm text-amber-700">Ambiente de homologação: a nota será emitida sem valor fiscal.</p>
        @endif

        @if ($pessoas->isEmpty())
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Para emitir, cadastre antes pelo menos uma <a href="{{ route('empresa.pessoas.create') }}" class="underline">pessoa</a>.
                Na devolução de compra, você também pode importar o XML da nota de compra (o fornecedor é cadastrado automaticamente).
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-medium">Corrija os pontos abaixo:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach
                </ul>
            </div>
        @endif

        {{-- Formulário separado (não pode ficar dentro do outro): importação do XML da compra --}}
        <form id="form-importar" method="POST" action="{{ route('empresa.notas.importar-compra') }}" enctype="multipart/form-data">@csrf</form>

        <form id="form-nota" method="POST" action="{{ $editando ? route('empresa.notas.update', $nota->id) : route('empresa.notas.store') }}" class="mt-6 space-y-6">
            @csrf
            @if ($editando) @method('PUT') @endif

            {{-- Tipo de operação --}}
            <section class="{{ $caixa }}">
                <h2 class="font-medium">O que você vai fazer?</h2>
                <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (Nota::OPERACOES as $codigo => $op)
                        <label class="flex cursor-pointer flex-col rounded-xl border border-slate-300 p-3 text-sm has-[:checked]:border-marca-500 has-[:checked]:bg-marca-50">
                            <span class="flex items-center gap-2 font-medium">
                                <input type="radio" name="operacao" value="{{ $codigo }}" @checked($operacaoAtual === $codigo)>
                                {{ $op['nome'] }}
                            </span>
                            <span class="mt-1 text-xs text-slate-500">{{ $op['ajuda'] }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            {{-- Devolução: nota de compra --}}
            <section id="bloco-devolucao" class="{{ $caixa }} {{ $operacaoAtual === 'devolucao' ? '' : 'hidden' }}">
                <h2 class="font-medium">Nota de compra que está sendo devolvida</h2>

                @unless ($editando)
                    <div class="mt-4 rounded-xl border border-marca-100 bg-marca-50 p-4">
                        <p class="text-sm font-medium text-marca-900">Mais fácil: importe o XML da nota de compra</p>
                        <p class="mt-1 text-xs text-marca-900">O sistema preenche o fornecedor, a chave da nota e os produtos com os impostos. Depois é só tirar o que não vai devolver.</p>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            {{-- O campo de arquivo fica escondido: o botão abre a janela e, escolhido o arquivo, envia sozinho --}}
                            <input type="file" id="arquivo-xml" name="xml" accept=".xml,text/xml,application/xml" form="form-importar" class="hidden">
                            <button type="button" id="botao-importar" class="rounded-lg bg-marca-600 px-4 py-2 text-sm font-medium text-white hover:bg-marca-700">Escolher o XML da nota de compra</button>
                            <span id="importando" class="hidden text-sm text-marca-900">Importando...</span>
                        </div>
                        @error('xml')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <p class="mt-4 text-sm text-slate-600">Ou informe a nota manualmente:</p>
                @endunless

                @php($ref = $referencias[0] ?? [])
                <div id="referencias" class="mt-3">
                    <div class="referencia rounded-xl border border-slate-200 p-3">
                        <input type="hidden" name="referencias[0][tipo]" value="nfe">
                        <label class="block text-xs font-medium text-slate-600">Chave de acesso da NF-e de compra (44 números)</label>
                        <input name="referencias[0][chave]" value="{{ ($ref['tipo'] ?? 'nfe') === 'nfe' ? ($ref['chave'] ?? '') : '' }}" inputmode="numeric" maxlength="54" data-chave
                               class="{{ $entrada }} font-mono tabular-nums" placeholder="Está no DANFE, abaixo do código de barras">
                        <p data-chave-info class="mt-1 text-xs text-slate-500">A chave já contém o CNPJ e a UF do emitente, o modelo, a série, o número e o mês da nota.</p>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        Uma nota de devolução referencia uma nota de compra. Se for devolver itens de duas compras, emita uma devolução para cada uma.
                    </p>
                </div>

                <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    Na devolução, o <strong>destinatário é o fornecedor</strong> e os impostos da compra (ICMS, ICMS-ST e IPI)
                    voltam destacados na nota, na proporção do que está sendo devolvido, para o fornecedor recuperar o crédito.
                </p>
            </section>

            {{-- Destinatário --}}
            <section class="{{ $caixa }}">
                <h2 class="font-medium" id="titulo-destinatario">{{ $operacaoAtual === 'devolucao' ? 'Fornecedor (destinatário da devolução)' : 'Destinatário' }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <x-selecao label="Pessoa" name="destinatario_id" required>
                            <option value="">Escolha...</option>
                            @foreach ($pessoas as $pessoa)
                                <option value="{{ $pessoa->id }}" @selected($pessoaAtual === $pessoa->id)>
                                    {{ $pessoa->razao_social }} - {{ $pessoa->documentoFormatado() }} ({{ $pessoa->municipio }}/{{ $pessoa->uf }})
                                </option>
                            @endforeach
                        </x-selecao>
                        <p class="mt-1 text-xs text-slate-500">Não encontrou? <a href="{{ route('empresa.pessoas.create') }}" target="_blank" class="text-marca-700 underline">Cadastre a pessoa</a> e recarregue esta página.</p>
                    </div>
                    <div>
                        <x-campo label="Natureza da operação" name="natureza_operacao" :value="$nota->natureza_operacao" required maxlength="60"
                                 ajuda="Preenchida conforme o tipo de operação." />
                    </div>
                </div>

                <label id="bloco-consumidor" class="mt-4 inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="consumidor_final" value="0">
                    <input type="checkbox" name="consumidor_final" value="1" class="rounded border-slate-300" @checked(old('consumidor_final', $nota->ind_final))>
                    Venda para consumidor final (o cliente vai usar, não revender)
                </label>
                <p id="aviso-destino" class="mt-2 hidden text-sm text-sky-800"></p>
            </section>

            {{-- Itens --}}
            <section class="{{ $caixa }}">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-medium">Produtos</h2>
                    <button type="button" id="adicionar-item" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">+ Adicionar produto</button>
                </div>

                <div id="itens" class="mt-4 space-y-3"></div>

                <dl class="mt-6 grid grid-cols-2 gap-4 border-t border-slate-200 pt-4 text-sm sm:ml-auto sm:max-w-2xl sm:grid-cols-5">
                    <div><dt class="text-slate-500">Produtos</dt><dd id="total-produtos" class="tabular-nums">R$ 0,00</dd></div>
                    <div><dt class="text-slate-500">Descontos</dt><dd id="total-descontos" class="tabular-nums">R$ 0,00</dd></div>
                    <div data-so-devolucao><dt class="text-slate-500">ICMS-ST</dt><dd id="total-st" class="tabular-nums">R$ 0,00</dd></div>
                    <div data-so-devolucao><dt class="text-slate-500">IPI devolvido</dt><dd id="total-ipi" class="tabular-nums">R$ 0,00</dd></div>
                    <div><dt class="text-slate-500">Total da nota</dt><dd id="total-nota" class="text-lg font-semibold tabular-nums">R$ 0,00</dd></div>
                </dl>
            </section>

            {{-- Frete e pagamento --}}
            <section class="{{ $caixa }}">
                <h2 class="font-medium">Frete e pagamento</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <x-selecao label="Frete" name="modalidade_frete" required>
                        @foreach (Nota::MODALIDADES_FRETE as $codigo => $nome)
                            <option value="{{ $codigo }}" @selected((int) old('modalidade_frete', $nota->modalidade_frete ?? 9) === $codigo)>{{ $nome }}</option>
                        @endforeach
                    </x-selecao>
                    <div data-com-pagamento>
                        <x-selecao label="Forma de pagamento" name="forma_pagamento">
                            @foreach (Nota::FORMAS_PAGAMENTO as $codigo => $nome)
                                @continue($codigo === '90')
                                <option value="{{ $codigo }}" @selected($formaAtual === (string) $codigo)>{{ $nome }}</option>
                            @endforeach
                        </x-selecao>
                    </div>
                    <div data-com-pagamento>
                        <x-selecao label="Condição" name="ind_pagamento">
                            <option value="0" @selected((int) old('ind_pagamento', $nota->ind_pagamento ?? 0) === 0)>À vista</option>
                            <option value="1" @selected((int) old('ind_pagamento', $nota->ind_pagamento ?? 0) === 1)>A prazo</option>
                        </x-selecao>
                    </div>
                    <p data-sem-pagamento class="self-end pb-2 text-sm text-slate-500 sm:col-span-2">Esta operação não tem pagamento: a nota sai como "sem pagamento".</p>
                    <div id="bloco-descricao-pagamento" class="sm:col-span-3 {{ $formaAtual === '99' ? '' : 'hidden' }}">
                        <x-campo label="Descreva a forma de pagamento" name="descricao_pagamento" :value="$nota->descricao_pagamento" maxlength="60" />
                    </div>
                </div>
            </section>

            {{-- Informações complementares --}}
            <section class="{{ $caixa }}">
                <label for="informacoes_complementares" class="font-medium">Informações complementares (opcional)</label>
                <p class="mt-1 text-xs text-slate-500">Saem no DANFE. O texto obrigatório do Simples Nacional (e, na devolução, a nota de compra) é incluído automaticamente.</p>
                <textarea id="informacoes_complementares" name="informacoes_complementares" rows="3" maxlength="2000"
                          class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">{{ old('informacoes_complementares', $nota->informacoes_complementares) }}</textarea>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ $editando ? route('empresa.notas.show', $nota->id) : route('empresa.notas.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700 hover:bg-slate-100">Cancelar</a>
                <button type="submit" name="acao" value="rascunho" class="rounded-lg border border-slate-300 px-4 py-2.5 font-medium text-slate-700 hover:bg-slate-100">Salvar rascunho</button>
                <button type="submit" name="acao" value="emitir" class="rounded-lg bg-marca-600 px-5 py-2.5 font-medium text-white hover:bg-marca-700">Emitir nota</button>
            </div>
        </form>
    </div>

    {{-- Modelo de uma linha de produto --}}
    <template id="modelo-item">
        <div class="item rounded-xl border border-slate-200 p-3">
            <div class="grid gap-3 sm:grid-cols-12 sm:items-end">
                <div class="sm:col-span-4">
                    <label class="block text-xs font-medium text-slate-600">Produto</label>
                    <select data-campo="produto_id" class="{{ $entrada }} bg-white"></select>
                    <div data-avulso class="hidden">
                        <p data-avulso-texto class="mt-1 py-2 text-sm"></p>
                        @foreach (['codigo', 'descricao', 'ncm', 'cest', 'gtin', 'unidade', 'origem', 'entrada'] as $oculto)
                            <input type="hidden" data-campo="{{ $oculto }}">
                        @endforeach
                    </div>
                </div>
                <div class="sm:col-span-1">
                    <label class="block text-xs font-medium text-slate-600">Qtd.</label>
                    <input data-campo="quantidade" inputmode="decimal" required class="{{ $entrada }} text-right tabular-nums">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-600">Valor unit. (R$)</label>
                    <input data-campo="valor_unitario" inputmode="numeric" required class="{{ $entrada }} text-right tabular-nums">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-600">Desconto (R$)</label>
                    <input data-campo="desconto" inputmode="numeric" class="{{ $entrada }} text-right tabular-nums">
                </div>
                <div class="sm:col-span-1">
                    <label class="block text-xs font-medium text-slate-600">CFOP</label>
                    <input data-campo="cfop" inputmode="numeric" required maxlength="5" class="{{ $entrada }} tabular-nums">
                </div>
                <div class="flex items-end justify-between gap-2 sm:col-span-2">
                    <div>
                        <span class="block text-xs font-medium text-slate-600">Total</span>
                        <span data-total class="mt-1 block py-2 text-sm font-medium tabular-nums">R$ 0,00</span>
                    </div>
                    <button type="button" data-remover class="rounded-lg px-2 py-2 text-sm text-red-700 hover:bg-red-50" aria-label="Remover produto">Remover</button>
                </div>
            </div>

            {{-- Impostos da compra que voltam na devolução --}}
            <div data-impostos class="mt-3 hidden rounded-lg bg-slate-50 p-3">
                <p class="text-xs font-medium text-slate-600">
                    Impostos devolvidos <span data-impostos-ajuda class="font-normal text-slate-500">(copie da nota de compra, proporcional à quantidade devolvida; deixe em branco se não houver)</span>
                </p>
                <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-7">
                    @foreach (['base_icms' => 'Base ICMS', 'aliq_icms' => 'Alíq. ICMS %', 'valor_icms' => 'Valor ICMS', 'base_icms_st' => 'Base ST', 'aliq_icms_st' => 'Alíq. ST %', 'valor_icms_st' => 'Valor ST', 'valor_ipi_devolvido' => 'IPI devolvido'] as $campo => $rotulo)
                        <div>
                            <label class="block text-[11px] text-slate-500">{{ $rotulo }}</label>
                            <input data-campo="{{ $campo }}" data-imposto inputmode="numeric" class="mt-0.5 block w-full rounded-md border border-slate-300 px-2 py-1.5 text-right text-sm tabular-nums">
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </template>

    <script>
        (() => {
            const produtos = @json($produtos);
            const pessoas = @json($pessoasJs);
            const operacoes = @json($operacoesJs);
            const ufEmpresa = @json($empresa->uf);
            const iniciais = @json(array_values($itens));
            const UFS = @json(array_flip(\App\Services\Nfe\MontadorXmlNfe::CODIGOS_UF));

            const $ = (id) => document.getElementById(id);
            const lista = $('itens');
            const modelo = $('modelo-item');
            const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            const numero = (t) => { t = String(t ?? '').trim(); if (!t) return 0; return Number(t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t) || 0; };
            const mascaraMoeda = (el) => { const c = el.value.replace(/\D/g, '').replace(/^0+/, ''); el.value = c ? (Number(c) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) : ''; };
            const mascaraCfop = (el) => { el.value = el.value.replace(/\D/g, '').slice(0, 4).replace(/^(\d)(\d)/, '$1.$2'); };
            const formatar2 = (v) => Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const operacao = () => document.querySelector('input[name=operacao]:checked')?.value || 'venda';
            const devolucao = () => operacao() === 'devolucao';
            const pessoaAtual = () => pessoas[$('destinatario_id').value];
            const cfopOperacao = (cfop) => {
                const n = String(cfop || '').replace(/\D/g, '');
                const p = pessoaAtual();
                const inter = p && p.uf !== ufEmpresa;
                if (inter && n.startsWith('5')) return '6' + n.slice(1);
                if (!inter && n.startsWith('6')) return '5' + n.slice(1);
                return n;
            };

            // ---------- Itens ----------
            function adicionar(dados = {}) {
                const item = modelo.content.firstElementChild.cloneNode(true);
                const campo = (n) => item.querySelector(`[data-campo=${n}]`);
                const select = campo('produto_id');
                const avulso = !dados.produto_id && dados.descricao;
                const entrada = typeof dados.entrada === 'string' ? (dados.entrada ? JSON.parse(dados.entrada) : null) : (dados.entrada || null);

                select.innerHTML = '<option value="">Escolha...</option>' + produtos.map((p) =>
                    `<option value="${p.id}">${p.codigo} - ${p.descricao.replace(/</g, '&lt;')} (${p.unidade})</option>`).join('');
                select.value = dados.produto_id || '';

                if (avulso) {
                    // Item que veio do XML da nota de compra: não está no cadastro de produtos
                    select.classList.add('hidden');
                    select.disabled = true;
                    item.querySelector('[data-avulso]').classList.remove('hidden');
                    item.querySelector('[data-avulso-texto]').textContent = `${dados.codigo} - ${dados.descricao} (${dados.unidade})`;
                    ['codigo', 'descricao', 'ncm', 'cest', 'gtin', 'unidade', 'origem'].forEach((n) => { campo(n).value = dados[n] ?? ''; });
                } else {
                    item.querySelectorAll('[data-avulso] input').forEach((el) => { el.disabled = true; });
                }
                if (entrada) {
                    campo('entrada').value = JSON.stringify(entrada);
                    item.dataset.entrada = campo('entrada').value;
                    campo('valor_unitario').readOnly = true;
                    campo('desconto').readOnly = true;
                    item.querySelectorAll('[data-imposto]').forEach((el) => { el.readOnly = true; el.classList.add('bg-slate-100'); });
                    item.querySelector('[data-impostos-ajuda]').textContent = `(da nota de compra, proporcional: comprou ${String(entrada.quantidade).replace('.', ',')} ${dados.unidade || ''})`;
                }

                campo('quantidade').value = dados.quantidade || '1';
                campo('valor_unitario').value = dados.valor_unitario || '';
                campo('desconto').value = dados.desconto || '';
                campo('cfop').value = dados.cfop || '';
                mascaraCfop(campo('cfop'));
                ['base_icms', 'aliq_icms', 'valor_icms', 'base_icms_st', 'aliq_icms_st', 'valor_icms_st', 'valor_ipi_devolvido']
                    .forEach((n) => { campo(n).value = dados[n] || ''; });

                select.addEventListener('change', () => {
                    const p = produtos.find((x) => String(x.id) === select.value);
                    if (p) {
                        campo('valor_unitario').value = formatar2(p.preco);
                        campo('cfop').value = cfopOperacao(operacoes[operacao()].cfop || p.cfop);
                        mascaraCfop(campo('cfop'));
                    }
                    recalcular();
                });
                ['valor_unitario', 'desconto'].forEach((n) => campo(n).addEventListener('input', () => { mascaraMoeda(campo(n)); recalcular(); }));
                item.querySelectorAll('[data-imposto]').forEach((el) => el.addEventListener('input', () => { mascaraMoeda(el); recalcular(); }));
                campo('quantidade').addEventListener('input', () => { campo('quantidade').value = campo('quantidade').value.replace(/[^\d,]/g, ''); recalcular(); });
                campo('cfop').addEventListener('input', () => mascaraCfop(campo('cfop')));
                item.querySelector('[data-remover]').addEventListener('click', () => { item.remove(); renomear(); recalcular(); });

                lista.appendChild(item);
                renomear();
                atualizarItem(item);
                recalcular();
            }

            // Os campos precisam de nomes itens[0][...], itens[1][...] na ordem atual
            function renomear() {
                lista.querySelectorAll('.item').forEach((item, i) => {
                    item.querySelectorAll('[data-campo]').forEach((el) => { el.name = `itens[${i}][${el.dataset.campo}]`; });
                    item.querySelector('[data-remover]').classList.toggle('invisible', lista.children.length === 1);
                });
            }

            // Item importado: impostos e desconto acompanham a quantidade devolvida
            function proporcionais(item) {
                if (!item.dataset.entrada) return;
                const e = JSON.parse(item.dataset.entrada);
                const campo = (n) => item.querySelector(`[data-campo=${n}]`);
                const fator = Math.min(1, numero(campo('quantidade').value) / Number(e.quantidade || 1));
                const val = (v) => (v ? formatar2(Math.round(v * fator * 100) / 100) : '');
                campo('desconto').value = val(e.valor_desconto);
                campo('base_icms').value = val(e.base_icms);
                campo('aliq_icms').value = e.aliq_icms ? formatar2(e.aliq_icms) : '';
                campo('valor_icms').value = val(e.valor_icms);
                campo('base_icms_st').value = val(e.base_icms_st);
                campo('aliq_icms_st').value = e.aliq_icms_st ? formatar2(e.aliq_icms_st) : '';
                campo('valor_icms_st').value = val(e.valor_icms_st);
                campo('valor_ipi_devolvido').value = val(e.valor_ipi);
            }

            function recalcular() {
                let produtosTotal = 0, descontos = 0, st = 0, ipi = 0;
                const dev = devolucao();
                lista.querySelectorAll('.item').forEach((item) => {
                    proporcionais(item);
                    const campo = (n) => item.querySelector(`[data-campo=${n}]`);
                    const bruto = Math.round(numero(campo('quantidade').value) * numero(campo('valor_unitario').value) * 100) / 100;
                    const d = numero(campo('desconto').value);
                    const s = dev ? numero(campo('valor_icms_st').value) : 0;
                    const i = dev ? numero(campo('valor_ipi_devolvido').value) : 0;
                    produtosTotal += bruto; descontos += d; st += s; ipi += i;
                    item.querySelector('[data-total]').textContent = moeda(bruto - d + s + i);
                });
                $('total-produtos').textContent = moeda(produtosTotal);
                $('total-descontos').textContent = moeda(descontos);
                $('total-st').textContent = moeda(st);
                $('total-ipi').textContent = moeda(ipi);
                $('total-nota').textContent = moeda(produtosTotal - descontos + st + ipi);
            }

            function atualizarItem(item) {
                const dev = devolucao();
                item.querySelector('[data-impostos]').classList.toggle('hidden', !dev);
                item.querySelectorAll('[data-imposto]').forEach((el) => { el.disabled = !dev; });
            }

            // ---------- Operação ----------
            let operacaoAnterior = operacao();
            function atualizarOperacao(mudou) {
                const op = operacao();
                const cfg = operacoes[op];
                const dev = op === 'devolucao';

                $('bloco-devolucao').classList.toggle('hidden', !dev);
                $('bloco-devolucao').querySelectorAll('input, select').forEach((el) => { if (!el.form || el.form.id === 'form-nota') el.disabled = !dev; });
                $('titulo-destinatario').textContent = dev ? 'Fornecedor (destinatário da devolução)' : 'Destinatário';
                document.querySelectorAll('[data-so-devolucao]').forEach((el) => el.classList.toggle('hidden', !dev));
                document.querySelectorAll('[data-com-pagamento]').forEach((el) => {
                    el.classList.toggle('hidden', !cfg.pagamento);
                    el.querySelectorAll('select').forEach((s) => { s.disabled = !cfg.pagamento; });
                });
                document.querySelectorAll('[data-sem-pagamento]').forEach((el) => el.classList.toggle('hidden', cfg.pagamento));
                $('bloco-descricao-pagamento').classList.toggle('hidden', !cfg.pagamento || $('forma_pagamento').value !== '99');

                if (mudou) {
                    // Natureza e CFOP acompanham a operação (o usuário pode alterar depois)
                    const natureza = $('natureza_operacao');
                    if (!natureza.value || natureza.value === operacoes[operacaoAnterior].natureza) natureza.value = cfg.natureza;
                    lista.querySelectorAll('.item').forEach((item) => {
                        if (item.dataset.entrada) return;
                        const p = produtos.find((x) => String(x.id) === item.querySelector('[data-campo=produto_id]').value);
                        const el = item.querySelector('[data-campo=cfop]');
                        const novo = cfg.cfop || (p ? p.cfop : '');
                        if (novo) { el.value = cfopOperacao(novo); mascaraCfop(el); }
                    });
                    operacaoAnterior = op;
                }
                lista.querySelectorAll('.item').forEach(atualizarItem);
                atualizarDestino();
                recalcular();
            }

            function atualizarDestino() {
                const pessoa = pessoaAtual();
                const aviso = $('aviso-destino');
                $('bloco-consumidor').classList.toggle('hidden', operacao() !== 'venda' || !pessoa || pessoa.pf || !pessoa.contribuinte);
                if (pessoa && pessoa.uf !== ufEmpresa) {
                    aviso.textContent = `Destinatário de outro estado (${pessoa.uf}): os CFOPs passam para 6.xxx automaticamente.`;
                    aviso.classList.remove('hidden');
                } else {
                    aviso.classList.add('hidden');
                }
                lista.querySelectorAll('[data-campo=cfop]').forEach((el) => { el.value = cfopOperacao(el.value); mascaraCfop(el); });
                conferirFornecedor();
            }

            // ---------- Notas referenciadas ----------
            function lerChave(input) {
                const info = input.closest('.referencia').querySelector('[data-chave-info]');
                const c = input.value.replace(/\D/g, '');
                input.value = c.replace(/(\d{4})(?=\d)/g, '$1 ');
                if (c.length !== 44) {
                    info.className = 'mt-1 text-xs text-slate-500';
                    info.textContent = c.length ? `Faltam ${44 - c.length} números.` : 'A chave já contém o CNPJ e a UF do emitente, o modelo, a série, o número e o mês da nota.';
                    return;
                }
                const cnpj = c.slice(6, 20).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
                info.className = 'mt-1 text-xs text-marca-700';
                info.textContent = `NF-e nº ${Number(c.slice(25, 34))}, série ${Number(c.slice(22, 25))}, de ${c.slice(4, 6)}/20${c.slice(2, 4)}, emitida por CNPJ ${cnpj} (${UFS[Number(c.slice(0, 2))] || '?'}).`;
                conferirFornecedor();
            }

            function conferirFornecedor() {
                const pessoa = pessoaAtual();
                document.querySelectorAll('[data-chave]').forEach((input) => {
                    const c = input.value.replace(/\D/g, '');
                    const info = input.closest('.referencia').querySelector('[data-chave-info]');
                    if (c.length === 44 && devolucao() && pessoa && pessoa.doc !== c.slice(6, 20) && !info.dataset.alerta) {
                        info.dataset.alerta = '1';
                        info.insertAdjacentHTML('beforeend', ' <span class="text-red-700">Atenção: o destinatário escolhido não é o emitente desta nota.</span>');
                    } else if (info.dataset.alerta && (!pessoa || pessoa.doc === c.slice(6, 20))) {
                        delete info.dataset.alerta;
                        lerChave(input);
                    }
                });
            }

            document.querySelectorAll('[data-chave]').forEach((chave) => {
                chave.addEventListener('input', () => lerChave(chave));
                if (chave.value) lerChave(chave);
            });

            // ---------- Importação do XML ----------
            const arquivoXml = $('arquivo-xml');
            if (arquivoXml) {
                $('botao-importar').addEventListener('click', () => arquivoXml.click());
                arquivoXml.addEventListener('change', () => {
                    if (!arquivoXml.files.length) return;
                    $('botao-importar').disabled = true;
                    $('importando').textContent = `Importando ${arquivoXml.files[0].name}...`;
                    $('importando').classList.remove('hidden');
                    $('form-importar').submit();
                });
            }

            // ---------- Ligações ----------
            $('adicionar-item').addEventListener('click', () => adicionar());
            $('destinatario_id').addEventListener('change', atualizarDestino);
            document.querySelectorAll('input[name=operacao]').forEach((r) => r.addEventListener('change', () => atualizarOperacao(true)));
            $('forma_pagamento').addEventListener('change', (e) => {
                $('bloco-descricao-pagamento').classList.toggle('hidden', e.target.value !== '99');
            });

            (iniciais.length ? iniciais : [{}]).forEach(adicionar);
            atualizarOperacao(false);
        })();
    </script>
@endsection
