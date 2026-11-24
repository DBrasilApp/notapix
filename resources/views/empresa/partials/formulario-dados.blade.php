{{--
    Formulário dos dados da empresa emitente. É o mesmo no cadastro da conta e em "Dados da empresa".

    @include('empresa.partials.formulario-dados', [
        'empresa' => $empresa, 'ufs' => $ufs,
        'modo' => 'cadastro' | 'edicao' | 'escritorio',   // escritorio = escritório cadastrando um cliente
        'urlCnpj' => route(...), // consulta do CNPJ (com '00000000000000' no lugar do número)
    ])
--}}
@php
    use App\Models\Empresa;
    use App\Support\Formata;

    $cadastro = in_array($modo, ['cadastro', 'escritorio'], true);   // empresa nova (CNPJ livre, busca automática)
    $comEmail = $modo !== 'cadastro';                                 // na criação da conta o e-mail vem do acesso
    $cnpjEditavel = $cadastro || $empresa->podeAlterarCnpj();
    $crtAtual = (int) old('crt', $empresa->crt);
    $bloco = $modo === 'cadastro' ? 'border-t border-slate-200 pt-6 first:border-t-0 first:pt-0' : 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6';

    // Numeração: "já emitia NF-e antes?" + série + número da última nota
    $ultimaNotaPix = ! $empresa->exists ? null : $empresa->notas()->whereNotNull('numero')->where('ambiente', $empresa->ambiente)
        ->orderByDesc('numero')->first(['numero', 'serie']);
    $proximo = (int) ($empresa->proximo_numero_nfe ?: 1);
    $jaEmitiu = old('ja_emitiu', ($proximo > 1 || (int) $empresa->serie_nfe > 1 || $ultimaNotaPix) ? '1' : '0');
    $ultimoNumero = old('ultimo_numero_nfe', $proximo > 1 ? $proximo - 1 : '');
    $numero = fn (int $n, string $titulo) => '<span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marca-600 text-xs text-white">'.$n.'</span> '.e($titulo);
@endphp

<div class="space-y-6" id="formulario-empresa">
    {{-- 1. Identificação --}}
    <section class="{{ $bloco }}">
        <h2 class="flex items-center gap-2 font-medium">{!! $numero(1, 'Dados da empresa') !!}</h2>
        <p class="mt-1 text-xs text-slate-500">
            {{ $cadastro ? 'Digite o CNPJ: buscamos o nome, o endereço e a inscrição estadual para você.' : 'Estes dados saem como emitente em todas as suas notas.' }}
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                @if ($cnpjEditavel)
                    <x-campo label="CNPJ" name="cnpj" :value="$empresa->cnpj ? Formata::cnpj($empresa->cnpj) : null" required inputmode="numeric"
                             maxlength="18" placeholder="00.000.000/0000-00" :autofocus="$cadastro"
                             :ajuda="$cadastro ? null : 'Pode ser corrigido até a primeira nota emitida.'" />
                @else
                    <label class="block text-sm font-medium text-slate-700">CNPJ</label>
                    <input type="text" value="{{ Formata::cnpj($empresa->cnpj) }}" disabled
                           class="mt-1 block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-500">
                    <p class="mt-1 text-xs text-slate-500">A empresa já emitiu notas. Para alterar o CNPJ, fale com o suporte.</p>
                @endif
                <p id="cnpj-status" class="mt-1 text-xs text-slate-500" aria-live="polite"></p>
            </div>
            <div>
                <x-campo label="Inscrição estadual" name="inscricao_estadual" :value="$empresa->inscricao_estadual" inputmode="numeric"
                         ajuda="Número do cadastro na SEFAZ (cartão do ICMS). Empresa só de serviços, sem inscrição estadual: deixe em branco." />
            </div>

            @unless ($cadastro)
                <div class="sm:col-span-2">
                    <button type="button" id="buscar-cnpj" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 hover:bg-slate-100">
                        Atualizar com os dados da Receita e da SEFAZ
                    </button>
                </div>
            @endunless

            <div class="sm:col-span-2">
                <x-campo label="Razão social" name="razao_social" :value="$empresa->razao_social" required maxlength="60" />
            </div>
            <div class="sm:col-span-2">
                <x-campo label="Nome fantasia (opcional)" name="nome_fantasia" :value="$empresa->nome_fantasia" maxlength="60" />
            </div>
        </div>

        <fieldset class="mt-4">
            <legend class="text-sm font-medium text-slate-700">Regime tributário</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                @foreach (Empresa::REGIMES_DISPONIVEIS as $codigo => $nome)
                    <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-300 bg-white p-3 text-sm has-[:checked]:border-marca-500 has-[:checked]:bg-marca-50">
                        <input type="radio" name="crt" value="{{ $codigo }}" class="mt-0.5" @checked($crtAtual === $codigo)>
                        <span>{{ $nome }}</span>
                    </label>
                @endforeach
            </div>
            <p class="mt-1 text-xs text-slate-500">{{ implode(' e ', Empresa::REGIMES_FUTUROS) }}: em breve.</p>
            <p id="crt-status" class="mt-1 text-xs"></p>
            @error('crt')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </fieldset>
        <input type="hidden" name="cnae" id="cnae" value="{{ old('cnae', $empresa->cnae) }}">
    </section>

    {{-- 2. Endereço e contato --}}
    <section class="{{ $bloco }}">
        <h2 class="flex items-center gap-2 font-medium">{!! $numero(2, 'Endereço e contato') !!}</h2>
        <p class="mt-1 text-xs text-slate-500">É o endereço que sai na nota. Confira se está igual ao do cadastro na SEFAZ.</p>
        <div class="mt-4">
            @include('partials.endereco', ['registro' => $empresa, 'ufs' => $ufs])
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @if ($comEmail)
                <x-campo label="E-mail" name="email" type="email" :value="$empresa->email" required ajuda="Recebe uma cópia do PDF e do XML de cada nota emitida." />
            @endif
            <x-campo label="Telefone (opcional)" name="telefone" :value="$empresa->telefone" inputmode="tel" placeholder="(27) 99999-9999" />
        </div>
    </section>

    {{-- 3. Numeração --}}
    <section class="{{ $bloco }}">
        <h2 class="flex items-center gap-2 font-medium">{!! $numero(3, 'Numeração das notas') !!}</h2>

        <fieldset class="mt-3">
            <legend class="text-sm text-slate-700">Esta empresa já emitiu NF-e (modelo 55) antes, em outro sistema ou no site da SEFAZ?</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-300 bg-white p-3 text-sm has-[:checked]:border-marca-500 has-[:checked]:bg-marca-50 {{ $ultimaNotaPix ? 'opacity-50' : '' }}">
                    <input type="radio" name="ja_emitiu" value="0" class="mt-0.5" @checked($jaEmitiu === '0') @disabled($ultimaNotaPix)>
                    <span>Não, será a primeira<span class="block text-xs text-slate-500">Começa na nota nº 1, série 1.</span></span>
                </label>
                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-300 bg-white p-3 text-sm has-[:checked]:border-marca-500 has-[:checked]:bg-marca-50">
                    <input type="radio" name="ja_emitiu" value="1" class="mt-0.5" @checked($jaEmitiu === '1')>
                    <span>Sim, já emitiu<span class="block text-xs text-slate-500">Continuamos a numeração de onde parou.</span></span>
                </label>
            </div>
        </fieldset>

        <div id="numeracao-anterior" class="mt-4 {{ $jaEmitiu === '1' ? '' : 'hidden' }}">
            <p class="text-xs text-slate-500">Veja na última nota emitida (no DANFE, perto do topo, aparece "Nº" e "Série").</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <x-campo label="Série" name="serie_nfe" type="number" min="1" max="889" :value="$empresa->serie_nfe ?: 1" />
                <x-campo label="Número da última nota emitida" name="ultimo_numero_nfe" type="number" min="1" :value="$ultimoNumero" />
            </div>
            @error('proximo_numero_nfe')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <p id="numeracao-resumo" class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700"></p>
        @if ($ultimaNotaPix)
            <p class="mt-2 text-xs text-slate-500">Última nota emitida pelo NotaPix: nº {{ $ultimaNotaPix->numero }}, série {{ $ultimaNotaPix->serie }}.</p>
        @endif
    </section>

    {{-- 4. NFC-e (só em "Dados da empresa") --}}
    @if ($modo === 'edicao')
        @php($ultimaNfce = $empresa->notas()->where('modelo', 65)->whereNotNull('numero')->where('ambiente', $empresa->ambiente)->max('numero'))
        <details class="{{ $bloco }}" @if ($errors->hasAny(['nfce_csc_homologacao', 'nfce_csc_producao', 'nfce_csc_id_homologacao', 'nfce_csc_id_producao', 'nfce_serie', 'nfce_ultimo_numero'])) open @endif>
            <summary class="flex cursor-pointer items-center gap-2 font-medium">{!! $numero(4, 'NFC-e (cupom fiscal) - opcional') !!}</summary>
            <div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                <p>Para vender no balcão com cupom fiscal (NFC-e), a empresa precisa estar <strong>credenciada para NFC-e na SEFAZ-ES</strong>.</p>
                <p class="mt-1"><strong>CSC</strong> é o "código de segurança do contribuinte": depois do credenciamento, a SEFAZ-ES envia o código (32 letras e números) e o ID dele
                    para o e-mail do contador, e eles também ficam na página de credenciamento da SEFAZ. Há um CSC para homologação (testes) e outro para produção.</p>
                <p class="mt-1">Com o QR Code versão 3, o CSC deixa de ser exigido. Preencha se a SEFAZ ainda pedir.</p>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-6">
                @foreach (['homologacao' => 'homologação (testes)', 'producao' => 'produção'] as $amb => $nomeAmb)
                    @php($cscGuardado = (string) $empresa->{'nfce_csc_'.$amb})
                    <div class="sm:col-span-6 rounded-lg border border-slate-200 p-3">
                        <p class="text-sm font-medium text-slate-700">CSC de {{ $nomeAmb }}</p>
                        @if ($cscGuardado !== '')
                            <p class="mt-1 text-xs text-marca-800">
                                Cadastrado: código terminado em <strong class="font-mono">{{ substr($cscGuardado, -4) }}</strong>
                                ({{ strlen($cscGuardado) }} caracteres){{ $empresa->{'nfce_csc_id_'.$amb} ? ', ID '.$empresa->{'nfce_csc_id_'.$amb} : '' }}.
                                @unless ($empresa->{'nfce_csc_id_'.$amb})<span class="text-red-700">Falta o ID do CSC.</span>@endunless
                            </p>
                        @endif
                        <div class="mt-2 grid gap-3 sm:grid-cols-6">
                            <div class="sm:col-span-4">
                                <x-campo :label="'Código CSC de '.$nomeAmb" :name="'nfce_csc_'.$amb" maxlength="36" autocomplete="off"
                                         :placeholder="$cscGuardado !== '' ? 'Deixe em branco para manter o já cadastrado' : '32 letras e números'" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-campo :label="'ID do CSC de '.$nomeAmb" :name="'nfce_csc_id_'.$amb" :value="$empresa->{'nfce_csc_id_'.$amb}"
                                         inputmode="numeric" maxlength="6" ajuda="Só números. Ex.: 1 ou 000001" />
                            </div>
                        </div>
                        @if ($cscGuardado !== '')
                            <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-600">
                                <input type="checkbox" name="nfce_remover_csc_{{ $amb }}" value="1" class="rounded border-slate-300"> Remover este CSC
                            </label>
                        @endif
                    </div>
                @endforeach
                <div class="sm:col-span-2">
                    <x-campo label="Série da NFC-e" name="nfce_serie" type="number" min="1" max="889" :value="$empresa->nfce_serie ?: 1"
                             ajuda="Diferente da série da NF-e, se possível." />
                </div>
                <div class="sm:col-span-2">
                    <x-campo label="Última NFC-e emitida" name="nfce_ultimo_numero" type="number" min="0" :value="max(0, (int) $empresa->nfce_proximo_numero - 1)"
                             ajuda="0 se nunca emitiu NFC-e." />
                </div>
                <div class="sm:col-span-2">
                    <x-selecao label="Largura da impressão" name="nfce_bobina">
                        <option value="80" @selected((int) old('nfce_bobina', $empresa->nfce_bobina ?: 80) === 80)>80 mm (padrão)</option>
                        <option value="72" @selected((int) old('nfce_bobina', $empresa->nfce_bobina) === 72)>72 mm (impressora 80 mm que corta a lateral)</option>
                        <option value="58" @selected((int) old('nfce_bobina', $empresa->nfce_bobina) === 58)>58 mm</option>
                    </x-selecao>
                    <p class="mt-1 text-xs text-slate-500">Se o cupom sair cortado à direita, escolha 72 mm.</p>
                </div>
            </div>
            @if ($ultimaNfce)
                <p class="mt-2 text-xs text-slate-500">Última NFC-e emitida pelo NotaPix: nº {{ $ultimaNfce }}.</p>
            @endif
        </details>

        {{-- 5. NFS-e (serviços) --}}
        @php($ultimaDps = $empresa->nfses()->whereNotNull('numero_dps')->where('ambiente', $empresa->ambiente)->max('numero_dps'))
        @php($meiAtual = $crtAtual === Empresa::CRT_MEI)
        <details id="nfse" class="{{ $bloco }}" @if ($errors->hasAny(['inscricao_municipal', 'nfse_serie', 'nfse_ultimo_numero', 'nfse_aliquota_simples', 'nfse_aliquota_iss', 'nfse_regime_apuracao']) || request()->query('abrir') === 'nfse') open @endif>
            <summary class="flex cursor-pointer items-center gap-2 font-medium">{!! $numero(5, 'NFS-e (notas de serviço) - opcional') !!}</summary>
            <div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                <p>Para emitir a <strong>NFS-e padrão nacional</strong> (serviços). A empresa usa o mesmo certificado digital e precisa estar liberada no portal da NFS-e Nacional
                    (se você já emite no portal www.nfse.gov.br, está liberada).</p>
                <p class="mt-1">Dica: abra uma nota que você já emitiu no portal e copie de lá a inscrição municipal (se aparecer) e o código do serviço.</p>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <x-campo label="Inscrição municipal" name="inscricao_municipal" :value="$empresa->inscricao_municipal" inputmode="numeric" maxlength="15"
                             ajuda="Só preencha se ela aparece nas notas que você emite no portal. Se aparecer erro dizendo que não deve ser informada, deixe em branco." />
                </div>
                <div class="sm:col-span-3">
                    @if ($meiAtual)
                        <input type="hidden" name="nfse_regime_apuracao" value="1">
                        <p class="mt-6 text-sm text-slate-600">MEI: os impostos já são pagos no DAS-SIMEI. Não há alíquota a informar.</p>
                    @else
                        <x-selecao label="Como o Simples recolhe os impostos" name="nfse_regime_apuracao">
                            <option value="1" @selected((int) old('nfse_regime_apuracao', $empresa->nfse_regime_apuracao ?: 1) === 1)>ISS e federais pelo Simples (o normal)</option>
                            <option value="2" @selected((int) old('nfse_regime_apuracao', $empresa->nfse_regime_apuracao) === 2)>Federais pelo Simples e ISS por fora (passou do sublimite)</option>
                            <option value="3" @selected((int) old('nfse_regime_apuracao', $empresa->nfse_regime_apuracao) === 3)>Federais e ISS por fora do Simples</option>
                        </x-selecao>
                    @endif
                </div>
                @unless ($meiAtual)
                    <div class="sm:col-span-3">
                        <x-campo label="% aproximado de tributos (alíquota efetiva do Simples)" name="nfse_aliquota_simples"
                                 :value="$empresa->nfse_aliquota_simples !== null ? number_format((float) $empresa->nfse_aliquota_simples, 2, ',', '.') : ''"
                                 inputmode="decimal" placeholder="Ex.: 6,00" ajuda="Vai em toda NFS-e (Lei da Transparência). É a alíquota da sua faixa do Simples: o contador informa." />
                    </div>
                    <div class="sm:col-span-3">
                        <x-campo label="Alíquota do ISS (só para ISS retido)" name="nfse_aliquota_iss"
                                 :value="$empresa->nfse_aliquota_iss !== null ? number_format((float) $empresa->nfse_aliquota_iss, 2, ',', '.') : ''"
                                 inputmode="decimal" placeholder="Ex.: 2,01" ajuda="A parte do ISS na sua faixa do Simples (entre 2% e 5%). Usada quando o cliente retém o ISS." />
                    </div>
                @endunless
                <div class="sm:col-span-3">
                    <x-campo label="Série da DPS" name="nfse_serie" type="number" min="1" max="89999" :value="$empresa->nfse_serie ?: 50"
                             ajuda="Use uma série só do NotaPix, diferente da que aparece nas notas que você emite no portal. Padrão: 50." />
                </div>
                <div class="sm:col-span-3">
                    <x-campo label="Última DPS enviada nesta série" name="nfse_ultimo_numero" type="number" min="0" :value="max(0, (int) $empresa->nfse_proximo_numero - 1)"
                             ajuda="0 se nunca enviou nesta série. O número da NFS-e é dado pelo governo, em sequência." />
                </div>
            </div>
            @if ($ultimaDps)
                <p class="mt-2 text-xs text-slate-500">Última DPS enviada pelo NotaPix: nº {{ $ultimaDps }}.</p>
            @endif
        </details>
    @endif
</div>

@include('partials.mascara-telefone')

<script>
    (() => {
        const $ = (id) => document.getElementById(id);
        const url = @json($urlCnpj);
        const cadastro = @json($cadastro);
        const cnpjFixo = @json($cnpjEditavel ? null : $empresa->cnpj);
        const doc = $('cnpj');
        const status = $('cnpj-status');
        const statusCrt = $('crt-status');
        const botao = $('buscar-cnpj');
        let ultimo = (doc ? doc.value : '').replace(/\D/g, '');
        if (cadastro) ultimo = '';

        const aviso = (el, texto, cor) => { el.textContent = texto; el.className = 'mt-1 text-xs ' + cor; };
        const preencher = (id, valor) => {
            const campo = $(id);
            if (!campo || valor === null || valor === undefined || valor === '') return;
            campo.value = valor;
            campo.dispatchEvent(new Event('input'));
        };
        const mascara = () => {
            doc.value = doc.value.replace(/\D/g, '').slice(0, 14)
                .replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
                .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
        };

        async function buscar(numeros) {
            ultimo = numeros;
            if (botao) botao.disabled = true;
            aviso(status, 'Buscando dados na Receita e na SEFAZ... (pode levar alguns segundos)', 'text-slate-500');

            try {
                const resposta = await fetch(url.replace('00000000000000', numeros), { headers: { Accept: 'application/json' } });
                const d = await resposta.json();
                if (!resposta.ok) { aviso(status, d.mensagem || 'Não foi possível consultar. Preencha manualmente.', 'text-amber-700'); return; }

                ['razao_social', 'nome_fantasia', 'logradouro', 'numero', 'complemento', 'bairro', 'municipio', 'uf', 'telefone', 'cnae']
                    .forEach((id) => preencher(id, d[id]));
                if (d.cep) $('cep').value = d.cep.replace(/^(\d{5})(\d)/, '$1-$2');
                if (d.codigo_municipio) $('codigo_municipio').value = d.codigo_municipio;
                const temIe = !!d.ie;
                if (temIe) preencher('inscricao_estadual', d.ie);

                if (d.crt) {
                    document.querySelector(`input[name="crt"][value="${d.crt}"]`).checked = true;
                    aviso(statusCrt, 'Regime preenchido conforme a Receita Federal.', 'text-marca-700');
                } else {
                    aviso(statusCrt, 'Na Receita, esta empresa não aparece como Simples Nacional nem MEI. Por enquanto o NotaPix atende apenas esses dois regimes.', 'text-red-700');
                }

                if (!d.ativa) {
                    aviso(status, `Atenção: na Receita este CNPJ está "${d.situacao}". Empresa nessa situação não consegue emitir notas.`, 'text-red-700');
                } else if (temIe) {
                    aviso(status, 'Dados preenchidos pela Receita e pela SEFAZ. Confira antes de salvar.', 'text-marca-700');
                } else if (d.ind_ie_dest === 9) {
                    aviso(status, d.ie_mensagem + ' Sem inscrição estadual a empresa não emite NF-e de mercadorias. Confira com seu contador.', 'text-amber-700');
                } else {
                    aviso(status, 'Dados preenchidos pela Receita. Confira e informe a inscrição estadual.', 'text-marca-700');
                    $('inscricao_estadual').focus();
                }
            } catch (e) {
                aviso(status, 'Não foi possível consultar o CNPJ agora. Preencha os dados manualmente.', 'text-amber-700');
            } finally {
                if (botao) botao.disabled = false;
            }
        }

        if (doc) {
            mascara();
            doc.addEventListener('input', () => {
                mascara();
                const numeros = doc.value.replace(/\D/g, '');
                if (numeros.length === 14 && numeros !== ultimo) buscar(numeros);
            });
        }
        if (botao) {
            botao.addEventListener('click', () => {
                const numeros = (doc ? doc.value : cnpjFixo || '').replace(/\D/g, '');
                numeros.length === 14 ? buscar(numeros) : aviso(status, 'Informe o CNPJ completo.', 'text-amber-700');
            });
        }

        // ---- Numeração ----
        const blocoAnterior = $('numeracao-anterior');
        const serie = $('serie_nfe');
        const ultimaNota = $('ultimo_numero_nfe');
        const resumo = $('numeracao-resumo');
        const jaEmitiu = () => document.querySelector('input[name="ja_emitiu"]:checked')?.value === '1';

        const atualizarNumeracao = () => {
            const sim = jaEmitiu();
            blocoAnterior.classList.toggle('hidden', !sim);
            serie.required = ultimaNota.required = sim;
            const n = parseInt(ultimaNota.value, 10);
            resumo.textContent = !sim
                ? 'A primeira nota emitida pelo NotaPix será a nº 1, série 1.'
                : (n > 0 ? `A próxima nota emitida pelo NotaPix será a nº ${n + 1}, série ${serie.value || 1}.`
                         : 'Informe a série e o número da última nota emitida.');
        };
        document.querySelectorAll('input[name="ja_emitiu"]').forEach((r) => r.addEventListener('change', atualizarNumeracao));
        [serie, ultimaNota].forEach((el) => el.addEventListener('input', atualizarNumeracao));
        atualizarNumeracao();
    })();
</script>
