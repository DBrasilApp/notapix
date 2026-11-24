@extends('layouts.app')

@php use App\Services\Nfe\SalvarNfce; @endphp

@section('titulo', 'Venda rápida (NFC-e)')

@section('conteudo')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Venda rápida <span class="text-base font-normal text-slate-500">NFC-e · cupom fiscal</span></h1>
            @if ($nota->exists)
                <p class="mt-1 text-sm text-amber-700">Corrigindo a NFC-e nº {{ $nota->numero }} (o número continua reservado).</p>
            @endif
        </div>
        <a href="{{ route('empresa.nfce.index') }}" class="text-sm text-slate-500 hover:text-marca-700">Cupons emitidos →</a>
    </div>

    @if ($pendencias)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-medium">Antes de emitir, resolva:</p>
            <ul class="mt-1 list-disc pl-5">@foreach ($pendencias as $p)<li>{{ $p }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="list-disc pl-5">@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($produtos->isEmpty())
        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
            Cadastre seus <a href="{{ route('empresa.produtos.create') }}" class="text-marca-700 underline">produtos</a> para começar a vender.
        </div>
    @else
        <form id="form-caixa" method="POST" action="{{ $nota->exists ? route('empresa.nfce.update', $nota->id) : route('empresa.nfce.store') }}"
              class="mt-4 grid gap-4 lg:grid-cols-3">
            @csrf
            @if ($nota->exists) @method('PUT') @endif

            {{-- Produtos --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                <div class="relative">
                    <label for="busca" class="text-sm font-medium text-slate-700">Produto</label>
                    <input id="busca" type="text" autocomplete="off" autofocus
                           placeholder="Digite o nome ou o código, ou passe o leitor de código de barras"
                           class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-3 text-base focus:border-marca-500 focus:outline-none focus:ring-2 focus:ring-marca-100">
                    <ul id="sugestoes" class="absolute z-10 mt-1 hidden max-h-72 w-full overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg"></ul>
                    <p id="busca-aviso" class="mt-1 text-xs text-slate-500">Enter adiciona. Código de barras ou código exato entra direto.</p>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr><th class="py-2">Produto</th><th class="py-2 text-center">Qtd.</th><th class="py-2 text-right">Unitário</th><th class="py-2 text-right">Total</th><th></th></tr>
                        </thead>
                        <tbody id="carrinho" class="divide-y divide-slate-100"></tbody>
                    </table>
                    <p id="carrinho-vazio" class="py-10 text-center text-sm text-slate-400">Nenhum produto ainda.</p>
                </div>
            </section>

            {{-- Pagamento --}}
            <section class="flex flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-slate-500">Total</div>
                <div id="total" class="text-4xl font-semibold tabular-nums">R$ 0,00</div>

                <div class="mt-5">
                    <div class="text-sm font-medium text-slate-700">Pagamento</div>
                    <div id="pagamentos" class="mt-2 space-y-2"></div>
                    <button type="button" id="mais-pagamento" class="mt-2 text-xs text-marca-700 hover:underline">+ outra forma de pagamento</button>
                </div>

                <dl class="mt-4 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Recebido</dt><dd id="recebido" class="tabular-nums">R$ 0,00</dd></div>
                    <div class="flex justify-between text-lg font-semibold"><dt>Troco</dt><dd id="troco" class="tabular-nums">R$ 0,00</dd></div>
                    <p id="falta" class="hidden text-sm text-red-700"></p>
                </dl>

                <details class="mt-4" @if (old('cpf_cnpj', $nota->destinatario_dados['cpf_cnpj'] ?? null)) open @endif>
                    <summary class="cursor-pointer text-sm text-slate-700">CPF/CNPJ na nota (opcional)</summary>
                    <div class="mt-2 space-y-2">
                        <input name="cpf_cnpj" id="cpf_cnpj" inputmode="numeric" maxlength="18" placeholder="CPF ou CNPJ do cliente"
                               value="{{ old('cpf_cnpj', $nota->destinatario_dados['cpf_cnpj'] ?? '') }}"
                               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <input name="nome" maxlength="60" placeholder="Nome (opcional)"
                               value="{{ old('nome', ($nota->destinatario_dados['cpf_cnpj'] ?? null) ? ($nota->destinatario_dados['razao_social'] ?? '') : '') }}"
                               class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </details>

                <div class="mt-auto pt-6">
                    <button type="submit" id="emitir" class="w-full rounded-xl bg-marca-600 px-4 py-4 text-lg font-semibold text-white hover:bg-marca-700 disabled:opacity-50">
                        Emitir NFC-e <span class="text-sm font-normal opacity-80">(F2)</span>
                    </button>
                    <button type="button" id="limpar" class="mt-2 w-full rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">Limpar venda (Esc)</button>
                </div>
            </section>
        </form>
    @endif

    <script>
        (() => {
            const form = document.getElementById('form-caixa');
            if (!form) return;

            const produtos = @json($produtos);
            const formas = @json(SalvarNfce::PAGAMENTOS);
            const $ = (id) => document.getElementById(id);
            const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            const numero = (t) => { t = String(t ?? '').trim(); if (!t) return 0; return Number(t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t) || 0; };
            const dois = (v) => Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const normal = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

            let carrinho = (@json(array_values($itens))).map((i) => ({ produto: produtos.find((p) => p.id === Number(i.produto_id)), qtd: Number(i.quantidade) || 1 }))
                .filter((i) => i.produto);
            let pagamentos = (@json(array_values($pagamentos))).map((p) => ({ forma: p.forma, valor: Number(p.valor) || 0, editado: true }));
            if (!pagamentos.length) pagamentos = [{ forma: '01', valor: 0, editado: false }];

            const total = () => Math.round(carrinho.reduce((s, i) => s + Math.round(i.qtd * i.produto.preco * 100) / 100, 0) * 100) / 100;

            // ---------- Carrinho ----------
            function adicionar(produto, qtd = 1) {
                const existente = carrinho.find((i) => i.produto.id === produto.id);
                existente ? existente.qtd += qtd : carrinho.push({ produto, qtd });
                desenhar();
            }

            function desenhar() {
                const corpo = $('carrinho');
                corpo.innerHTML = '';
                carrinho.forEach((item, n) => {
                    const linha = document.createElement('tr');
                    linha.innerHTML = `
                        <td class="py-2 pr-2"><div class="font-medium"></div><div class="text-xs text-slate-500"></div></td>
                        <td class="py-2"><div class="flex items-center justify-center gap-1">
                            <button type="button" data-menos class="h-7 w-7 rounded border border-slate-300">−</button>
                            <input data-qtd inputmode="decimal" class="w-16 rounded border border-slate-300 px-1 py-1 text-center tabular-nums">
                            <button type="button" data-mais class="h-7 w-7 rounded border border-slate-300">+</button></div></td>
                        <td class="py-2 text-right tabular-nums">${moeda(item.produto.preco)}</td>
                        <td class="py-2 text-right font-medium tabular-nums">${moeda(Math.round(item.qtd * item.produto.preco * 100) / 100)}</td>
                        <td class="py-2 pl-2 text-right"><button type="button" data-remover class="text-red-700" aria-label="Remover">✕</button>
                            <input type="hidden" name="itens[${n}][produto_id]" value="${item.produto.id}">
                            <input type="hidden" name="itens[${n}][quantidade]" value="${item.qtd}"></td>`;
                    linha.querySelector('.font-medium').textContent = item.produto.descricao;
                    linha.querySelector('.text-xs').textContent = `${item.produto.codigo} · ${item.produto.unidade}`;
                    const qtd = linha.querySelector('[data-qtd]');
                    qtd.value = String(item.qtd).replace('.', ',');
                    qtd.addEventListener('change', () => { const v = numero(qtd.value); v > 0 ? item.qtd = v : carrinho.splice(n, 1); desenhar(); });
                    linha.querySelector('[data-menos]').addEventListener('click', () => { item.qtd > 1 ? item.qtd -= 1 : carrinho.splice(n, 1); desenhar(); });
                    linha.querySelector('[data-mais]').addEventListener('click', () => { item.qtd += 1; desenhar(); });
                    linha.querySelector('[data-remover]').addEventListener('click', () => { carrinho.splice(n, 1); desenhar(); });
                    corpo.appendChild(linha);
                });
                $('carrinho-vazio').classList.toggle('hidden', carrinho.length > 0);
                $('total').textContent = moeda(total());
                desenharPagamentos();
            }

            // ---------- Busca (nome, código ou código de barras) ----------
            const busca = $('busca');
            const sugestoes = $('sugestoes');
            let achados = [];

            function procurar() {
                const termo = normal(busca.value.trim());
                if (!termo) { sugestoes.classList.add('hidden'); return; }
                const palavras = termo.split(/\s+/);
                achados = produtos.filter((p) => {
                    const texto = normal(`${p.descricao} ${p.codigo} ${p.gtin}`);
                    return palavras.every((w) => texto.includes(w));
                }).slice(0, 8);
                sugestoes.innerHTML = achados.length ? '' : '<li class="px-3 py-2 text-sm text-slate-500">Nenhum produto encontrado.</li>';
                achados.forEach((p, i) => {
                    const li = document.createElement('li');
                    li.className = 'flex cursor-pointer justify-between gap-3 px-3 py-2 text-sm hover:bg-marca-50' + (i === 0 ? ' bg-slate-50' : '');
                    li.innerHTML = '<span></span><span class="tabular-nums text-slate-600"></span>';
                    li.firstChild.textContent = `${p.codigo} - ${p.descricao}`;
                    li.lastChild.textContent = moeda(p.preco);
                    li.addEventListener('mousedown', (e) => { e.preventDefault(); escolher(p); });
                    sugestoes.appendChild(li);
                });
                sugestoes.classList.remove('hidden');
            }

            function escolher(p) {
                adicionar(p);
                avisar(`Lançado: ${p.descricao}`, 'text-marca-700');
                achados = [];
                busca.value = '';
                sugestoes.classList.add('hidden');
                busca.focus();
            }

            // Código de barras: compara só os números, sem zeros à esquerda (GTIN-8/12/13/14)
            const semZeros = (t) => String(t || '').replace(/\D/g, '').replace(/^0+/, '');
            const porCodigo = (codigo) => {
                const limpo = codigo.trim();
                const gtin = semZeros(limpo);
                return produtos.find((p) => (gtin.length >= 8 && p.gtin && semZeros(p.gtin) === gtin) || p.codigo === limpo);
            };

            const avisoBusca = $('busca-aviso');
            const avisar = (texto, cor) => { avisoBusca.textContent = texto; avisoBusca.className = 'mt-1 text-xs ' + cor; };

            // Lê o que está no campo e lança. O campo é SEMPRE limpo depois, para a próxima leitura não se juntar a esta.
            let timerLeitor = null;
            function processar(viaEnter) {
                clearTimeout(timerLeitor);
                const termo = busca.value.trim();
                if (!termo) return;

                // "3*código" vende 3 unidades
                const m = termo.match(/^(\d+(?:[.,]\d+)?)\s*\*\s*(.+)$/);
                const qtd = m ? numero(m[1]) : 1;
                const produto = porCodigo(m ? m[2] : termo);

                if (produto) {
                    adicionar(produto, qtd);
                    avisar(`Lançado: ${produto.descricao}`, 'text-marca-700');
                } else if (viaEnter && achados.length && !/^\d{8,14}$/.test(termo)) {
                    adicionar(achados[0], qtd);
                    avisar(`Lançado: ${achados[0].descricao}`, 'text-marca-700');
                } else if (viaEnter) {
                    avisar(`Código ${termo} não encontrado. Confira o código de barras no cadastro do produto.`, 'text-red-700');
                } else {
                    return; // ainda digitando
                }

                busca.value = '';
                achados = [];
                sugestoes.classList.add('hidden');
                busca.focus();
            }

            busca.addEventListener('input', () => {
                procurar();
                // Leitor que não manda Enter: código completo e exato entra sozinho
                clearTimeout(timerLeitor);
                if (/^\d{8,14}$/.test(busca.value.trim()) && porCodigo(busca.value)) {
                    timerLeitor = setTimeout(() => processar(false), 120);
                }
            });
            busca.addEventListener('blur', () => setTimeout(() => sugestoes.classList.add('hidden'), 150));
            busca.addEventListener('keydown', (e) => {
                // Enter (e Tab, que alguns leitores mandam no fim) lançam o código
                if (e.key === 'Enter' || (e.key === 'Tab' && busca.value.trim())) {
                    e.preventDefault();
                    processar(true);
                }
            });
            busca.addEventListener('focus', () => busca.select());

            // ---------- Pagamentos ----------
            function desenharPagamentos() {
                const caixa = $('pagamentos');
                caixa.innerHTML = '';
                // O primeiro pagamento acompanha o total até o usuário mexer
                const outros = pagamentos.slice(1).reduce((s, p) => s + p.valor, 0);
                if (!pagamentos[0].editado) pagamentos[0].valor = Math.max(0, Math.round((total() - outros) * 100) / 100);

                pagamentos.forEach((pag, n) => {
                    const linha = document.createElement('div');
                    linha.className = 'flex gap-2';
                    linha.innerHTML = `<select name="pagamentos[${n}][forma]" class="w-1/2 rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm"></select>
                        <input name="pagamentos[${n}][valor]" inputmode="numeric" class="w-1/2 rounded-lg border border-slate-300 px-2 py-2 text-right text-sm tabular-nums">
                        ${n > 0 ? '<button type="button" class="px-1 text-red-700" aria-label="Remover">✕</button>' : ''}`;
                    const sel = linha.querySelector('select');
                    Object.entries(formas).forEach(([codigo, nome]) => sel.add(new Option(nome, codigo, false, codigo === pag.forma)));
                    const valor = linha.querySelector('input');
                    valor.value = dois(pag.valor);
                    sel.addEventListener('change', () => { pag.forma = sel.value; resumo(); });
                    valor.addEventListener('input', () => {
                        const c = valor.value.replace(/\D/g, '').replace(/^0+/, '');
                        valor.value = c ? (Number(c) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) : '';
                        pag.valor = numero(valor.value);
                        pag.editado = true;
                        resumo();
                    });
                    linha.querySelector('button')?.addEventListener('click', () => { pagamentos.splice(n, 1); desenharPagamentos(); });
                    caixa.appendChild(linha);
                });
                resumo();
            }

            function resumo() {
                const recebido = pagamentos.reduce((s, p) => s + p.valor, 0);
                const dinheiro = pagamentos.filter((p) => p.forma === '01').reduce((s, p) => s + p.valor, 0);
                const falta = Math.round((total() - recebido) * 100) / 100;
                const troco = Math.max(0, -falta);
                $('recebido').textContent = moeda(recebido);
                $('troco').textContent = moeda(troco);
                const aviso = $('falta');
                if (falta > 0.001) { aviso.textContent = `Falta receber ${moeda(falta)}.`; aviso.classList.remove('hidden'); }
                else if (troco > dinheiro + 0.001) { aviso.textContent = 'Só dá troco em dinheiro: confira os valores.'; aviso.classList.remove('hidden'); }
                else aviso.classList.add('hidden');
                $('emitir').disabled = !carrinho.length || falta > 0.001 || troco > dinheiro + 0.001;
            }

            $('mais-pagamento').addEventListener('click', () => {
                const recebido = pagamentos.reduce((s, p) => s + p.valor, 0);
                pagamentos.push({ forma: '17', valor: Math.max(0, Math.round((total() - recebido) * 100) / 100), editado: true });
                desenharPagamentos();
            });

            // ---------- Atalhos ----------
            function limpar() {
                carrinho = [];
                pagamentos = [{ forma: '01', valor: 0, editado: false }];
                desenhar();
                busca.focus();
            }
            $('limpar').addEventListener('click', () => { if (!carrinho.length || confirm('Limpar esta venda?')) limpar(); });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'F2') { e.preventDefault(); if (!$('emitir').disabled) form.requestSubmit(); }
                if (e.key === 'Escape' && document.activeElement !== busca && carrinho.length && confirm('Limpar esta venda?')) limpar();
            });
            form.addEventListener('submit', () => { $('emitir').disabled = true; $('emitir').firstChild.textContent = 'Emitindo... '; });

            const doc = $('cpf_cnpj');
            doc.addEventListener('input', () => {
                const n = doc.value.replace(/\D/g, '').slice(0, 14);
                doc.value = n.length <= 11
                    ? n.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2')
                    : n.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
            });

            desenhar();
        })();
    </script>
@endsection
