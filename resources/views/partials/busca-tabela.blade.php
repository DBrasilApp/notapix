{{--
    Campo com pesquisa numa tabela oficial (NCM, CFOP...).
    O código pode ser digitado direto (mostra a descrição ao completar) ou escolhido na pesquisa.

    @include('partials.busca-tabela', [
        'nome' => 'ncm', 'rotulo' => 'NCM', 'valor' => '6109.10.00', 'descricaoAtual' => '...',
        'digitos' => 8, 'mascara' => 'ncm', 'placeholder' => '0000.00.00',
        'urlBusca' => route(...), 'urlCodigo' => url(...),
        'disponivel' => true, 'textoBusca' => 'Ex.: ...', 'ajudaBusca' => '...', 'abreComSugestoes' => false,
        'campoExtra' => ['label' => 'CEST (opcional)', 'name' => 'cest', 'value' => '...', 'ajuda' => '...'], // opcional, ao lado do botão
    ])
--}}
@php($disponivel = $disponivel ?? true)
@php($obrigatorio = $obrigatorio ?? true)

<div data-busca-tabela="{{ $nome }}"
     data-url-busca="{{ $urlBusca }}" data-url-codigo="{{ $urlCodigo }}"
     data-digitos="{{ $digitos }}" data-mascara="{{ $mascara }}"
     data-disponivel="{{ $disponivel ? '1' : '0' }}" data-sugestoes="{{ ($abreComSugestoes ?? false) ? '1' : '0' }}">
    <div class="grid gap-4 sm:grid-cols-6">
        <div class="sm:col-span-2">
            <x-campo :label="$rotulo" :name="$nome" :value="$valor" :required="$obrigatorio" inputmode="numeric" :placeholder="$placeholder" autocomplete="off" />
        </div>
        <div class="flex items-end sm:col-span-2">
            <button type="button" data-abrir @disabled(! $disponivel)
                    class="w-full rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50">
                Pesquisar {{ $rotulo }}
            </button>
        </div>
        @if (! empty($campoExtra))
            <div class="sm:col-span-2">
                <x-campo :label="$campoExtra['label']" :name="$campoExtra['name']" :value="$campoExtra['value'] ?? null" inputmode="numeric"
                         :maxlength="$campoExtra['maxlength'] ?? null" :ajuda="$campoExtra['ajuda'] ?? null" />
            </div>
        @endif
    </div>

    <p data-descricao class="mt-2 text-sm {{ $descricaoAtual ? 'text-marca-700' : 'text-slate-500' }}" aria-live="polite">{{ $descricaoAtual }}</p>

    <div data-painel class="mt-4 hidden rounded-xl border border-slate-200 bg-slate-50 p-4">
        <label class="block text-sm font-medium text-slate-700" for="{{ $nome }}-busca">Pesquisar na tabela oficial</label>
        <input id="{{ $nome }}-busca" data-busca type="search" autocomplete="off" placeholder="{{ $textoBusca }}"
               class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 shadow-sm focus:border-marca-500 focus:ring-2 focus:ring-marca-100 focus:outline-none">
        @if (! empty($ajudaBusca))
            <p class="mt-1 text-xs text-slate-500">{{ $ajudaBusca }}</p>
        @endif
        <ul data-lista class="mt-3 max-h-80 divide-y divide-slate-200 overflow-y-auto rounded-lg bg-white"></ul>
    </div>
</div>

@once
    <script>
        // Liga todos os campos com pesquisa da página
        document.addEventListener('DOMContentLoaded', () => {
            const mascaras = {
                ncm: (v) => v.replace(/\D/g, '').slice(0, 8).replace(/^(\d{4})(\d)/, '$1.$2').replace(/^(\d{4})\.(\d{2})(\d)/, '$1.$2.$3'),
                cfop: (v) => v.replace(/\D/g, '').slice(0, 4).replace(/^(\d)(\d)/, '$1.$2'),
                servico: (v) => v.replace(/\D/g, '').slice(0, 6).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{2})(\d)/, '$1.$2.$3'),
            };
            const escapar = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            document.querySelectorAll('[data-busca-tabela]').forEach((bloco) => {
                const nome = bloco.dataset.buscaTabela;
                const digitos = Number(bloco.dataset.digitos);
                const mascara = mascaras[bloco.dataset.mascara] || ((v) => v.replace(/\D/g, '').slice(0, digitos));
                const disponivel = bloco.dataset.disponivel === '1';
                const campo = bloco.querySelector(`#${nome}`);
                const descricao = bloco.querySelector('[data-descricao]');
                const painel = bloco.querySelector('[data-painel]');
                const busca = bloco.querySelector('[data-busca]');
                const lista = bloco.querySelector('[data-lista]');
                const abrir = bloco.querySelector('[data-abrir]');
                const cabecalho = { headers: { Accept: 'application/json' } };

                const mostrar = (texto, tipo) => {
                    descricao.textContent = texto || '';
                    descricao.className = 'mt-2 text-sm ' + ({ ok: 'text-marca-700', erro: 'text-red-600' }[tipo] || 'text-slate-500');
                };

                campo.value = mascara(campo.value);
                let conferido = campo.value.replace(/\D/g, '');

                campo.addEventListener('input', async () => {
                    campo.value = mascara(campo.value);
                    const codigo = campo.value.replace(/\D/g, '');

                    if (codigo.length < digitos) {
                        mostrar(codigo.length ? `Informe os ${digitos} dígitos.` : '', '');
                        conferido = null;
                        return;
                    }
                    if (codigo === conferido || !disponivel) return;
                    conferido = codigo;

                    try {
                        const resposta = await fetch(`${bloco.dataset.urlCodigo}/${codigo}`, cabecalho);
                        const dados = await resposta.json();
                        resposta.ok ? mostrar(dados.descricao_completa, 'ok') : mostrar(dados.mensagem, 'erro');
                    } catch (e) {
                        mostrar('Não foi possível conferir agora.', '');
                    }
                });

                abrir.addEventListener('click', () => {
                    painel.classList.toggle('hidden');
                    if (painel.classList.contains('hidden')) return;
                    busca.focus();
                    if (bloco.dataset.sugestoes === '1' && !busca.value) pesquisar();
                });

                let espera;
                busca.addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(pesquisar, 300); });
                busca.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });

                async function pesquisar() {
                    const termo = busca.value.trim();
                    if (termo.length === 1 || (termo === '' && bloco.dataset.sugestoes !== '1')) { lista.innerHTML = ''; return; }

                    lista.innerHTML = '<li class="px-3 py-2 text-sm text-slate-500">Pesquisando...</li>';
                    try {
                        const itens = await (await fetch(`${bloco.dataset.urlBusca}?q=${encodeURIComponent(termo)}`, cabecalho)).json();
                        lista.innerHTML = itens.length
                            ? (termo === '' ? '<li class="px-3 py-2 text-xs font-medium uppercase tracking-wide text-slate-500">Mais usados</li>' : '') +
                              itens.map((i) => `
                                <li>
                                    <button type="button" data-codigo="${escapar(i.codigo)}" data-texto="${escapar(i.descricao_completa)}"
                                            class="block w-full px-3 py-2 text-left text-sm hover:bg-marca-50 focus:bg-marca-50 focus:outline-none">
                                        <span class="font-medium tabular-nums">${escapar(i.codigo_formatado)}</span>
                                        <span class="block text-slate-600">${escapar(i.descricao_completa)}</span>
                                    </button>
                                </li>`).join('')
                            : '<li class="px-3 py-2 text-sm text-slate-500">Nada encontrado. Tente outras palavras.</li>';
                    } catch (e) {
                        lista.innerHTML = '<li class="px-3 py-2 text-sm text-red-600">Não foi possível pesquisar agora.</li>';
                    }
                }

                lista.addEventListener('click', (e) => {
                    const botao = e.target.closest('button[data-codigo]');
                    if (!botao) return;
                    campo.value = mascara(botao.dataset.codigo);
                    conferido = botao.dataset.codigo;
                    mostrar(botao.dataset.texto, 'ok');
                    campo.dispatchEvent(new Event('change'));
                    painel.classList.add('hidden');
                    campo.focus();
                });
            });
        });
    </script>
@endonce
