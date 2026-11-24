@extends('layouts.app')

@php
    use App\Models\Destinatario;
    $editando = $pessoa->exists;
    $tipo = old('tipo_pessoa', $pessoa->tipo_pessoa ?: Destinatario::JURIDICA);
    $indicador = (int) old('ind_ie_dest', $pessoa->ind_ie_dest ?: Destinatario::IE_CONTRIBUINTE);
@endphp

@section('titulo', $editando ? 'Editar pessoa' : 'Nova pessoa')

@section('conteudo')
    <div class="max-w-3xl">
        <a href="{{ route('empresa.pessoas.index') }}" class="text-sm text-slate-500 hover:text-marca-700">← Pessoas</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $editando ? $pessoa->razao_social : 'Nova pessoa' }}</h1>

        <form method="POST" action="{{ $editando ? route('empresa.pessoas.update', $pessoa->id) : route('empresa.pessoas.store') }}" class="mt-6 space-y-6">
            @csrf
            @if ($editando) @method('PUT') @endif

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                {{-- Pessoa jurídica / física --}}
                <div class="inline-grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-sm" role="radiogroup" aria-label="Tipo de pessoa">
                    @foreach ([Destinatario::JURIDICA => 'Pessoa jurídica', Destinatario::FISICA => 'Pessoa física'] as $valor => $rotulo)
                        <label class="cursor-pointer">
                            <input type="radio" name="tipo_pessoa" value="{{ $valor }}" class="peer sr-only" @checked($tipo === $valor)>
                            <span class="block rounded-md px-4 py-2 text-center text-slate-600 peer-checked:bg-white peer-checked:font-medium peer-checked:text-slate-900 peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-marca-500">{{ $rotulo }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-campo :label="$tipo === Destinatario::FISICA ? 'CPF' : 'CNPJ'" name="cpf_cnpj" :value="$pessoa->cpf_cnpj" required inputmode="numeric" data-rotulo-pf="CPF" data-rotulo-pj="CNPJ" />
                        <p id="cnpj-status" class="mt-1 text-xs text-slate-500" aria-live="polite" data-so-pj>Digite o CNPJ e os dados são preenchidos automaticamente.</p>
                    </div>
                    <div class="hidden sm:block"></div>

                    <div class="sm:col-span-2">
                        <x-campo :label="$tipo === Destinatario::FISICA ? 'Nome completo' : 'Razão social'" name="razao_social" :value="$pessoa->razao_social" required maxlength="60" data-rotulo-pf="Nome completo" data-rotulo-pj="Razão social" />
                    </div>

                    <div class="sm:col-span-2" data-so-pj>
                        <x-campo label="Nome fantasia (opcional)" name="nome_fantasia" :value="$pessoa->nome_fantasia" maxlength="60" />
                    </div>

                    <div data-so-pj>
                        <x-selecao label="Inscrição estadual" name="ind_ie_dest">
                            @foreach (Destinatario::INDICADORES_IE as $codigo => $descricao)
                                <option value="{{ $codigo }}" @selected($indicador === $codigo)>{{ $descricao }}</option>
                            @endforeach
                        </x-selecao>
                    </div>
                    <div data-so-pj data-so-contribuinte>
                        <x-campo label="Número da inscrição estadual" name="inscricao_estadual" :value="$pessoa->inscricao_estadual" inputmode="numeric" />
                    </div>
                </div>

                <p class="mt-4 hidden text-xs text-slate-500" data-so-pf>
                    Pessoa física é emitida como não contribuinte do ICMS e consumidor final.
                </p>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-medium">Endereço</h2>
                <p class="mt-1 text-xs text-slate-500">Digite o CEP e o endereço é preenchido automaticamente.</p>
                <div class="mt-4">
                    @include('partials.endereco', ['registro' => $pessoa, 'ufs' => $ufs])
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-medium">Contato</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-campo label="E-mail (opcional)" name="email" type="email" :value="$pessoa->email" ajuda="Se informado, recebe o PDF e o XML das notas." />
                    <x-campo label="Telefone (opcional)" name="telefone" :value="$pessoa->telefone" inputmode="tel" placeholder="(27) 99999-9999" />
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    @if ($editando)
                        <button type="submit" form="excluir" class="text-sm text-red-700 hover:underline">Excluir pessoa</button>
                    @endif
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('empresa.pessoas.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-slate-700 hover:bg-slate-100">Cancelar</a>
                    <button type="submit" class="rounded-lg bg-marca-600 px-5 py-2.5 font-medium text-white hover:bg-marca-700">Salvar</button>
                </div>
            </div>
        </form>

        @if ($editando)
            <form id="excluir" method="POST" action="{{ route('empresa.pessoas.destroy', $pessoa->id) }}"
                  onsubmit="return confirm('Excluir esta pessoa? As notas já emitidas para ela não são afetadas.')">
                @csrf
                @method('DELETE')
            </form>
        @endif
    </div>

    @include('partials.mascara-telefone')

    <script>
        (() => {
            const radios = document.querySelectorAll('input[name="tipo_pessoa"]');
            const doc = document.getElementById('cpf_cnpj');
            const indicador = document.getElementById('ind_ie_dest');

            const tipoAtual = () => document.querySelector('input[name="tipo_pessoa"]:checked').value;

            const mascara = () => {
                const n = doc.value.replace(/\D/g, '');
                doc.value = tipoAtual() === 'F'
                    ? n.slice(0, 11).replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2')
                    : n.slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
            };

            const atualizar = () => {
                const fisica = tipoAtual() === 'F';

                document.querySelectorAll('[data-so-pj]').forEach((el) => el.classList.toggle('hidden', fisica));
                document.querySelectorAll('[data-so-pf]').forEach((el) => el.classList.toggle('hidden', !fisica));

                // Troca os rótulos (CPF/CNPJ, Nome/Razão social)
                document.querySelectorAll('[data-rotulo-pf]').forEach((input) => {
                    const label = document.querySelector(`label[for="${input.id}"]`);
                    if (label) label.textContent = fisica ? input.dataset.rotuloPf : input.dataset.rotuloPj;
                });

                const contribuinte = !fisica && indicador.value === '1';
                document.querySelectorAll('[data-so-contribuinte]').forEach((el) => el.classList.toggle('hidden', !contribuinte));
                document.getElementById('inscricao_estadual').required = contribuinte;

                mascara();
            };

            // ---- Preenchimento pelo CNPJ (Receita Federal + inscrição estadual na SEFAZ) ----
            const editando = @json($editando);
            const urlCnpj = @json(route('empresa.cnpj.mostrar', '00000000000000'));
            const status = document.getElementById('cnpj-status');
            let ultimoCnpj = editando ? doc.value.replace(/\D/g, '') : '';

            const preencher = (id, valor, sobrescrever) => {
                const campo = document.getElementById(id);
                if (!campo || valor === null || valor === undefined || valor === '') return;
                if (!sobrescrever && campo.value.trim() !== '') return;
                campo.value = valor;
                campo.dispatchEvent(new Event('input'));
            };

            async function buscarCnpj(manual) {
                const numeros = doc.value.replace(/\D/g, '');
                if (tipoAtual() !== 'J' || numeros.length !== 14) {
                    if (manual) status.textContent = 'Digite os 14 números do CNPJ.';
                    return;
                }
                ultimoCnpj = numeros;
                status.className = 'mt-1 text-xs text-slate-500';
                status.textContent = 'Buscando dados do CNPJ... (pode levar alguns segundos)';

                try {
                    const resposta = await fetch(urlCnpj.replace('00000000000000', numeros), { headers: { Accept: 'application/json' } });
                    const d = await resposta.json();

                    if (!resposta.ok) {
                        status.className = 'mt-1 text-xs text-amber-700';
                        status.textContent = d.mensagem || 'Não foi possível consultar. Preencha manualmente.';
                        return;
                    }

                    // A busca só roda quando o CNPJ muda: os dados novos substituem os anteriores
                    const sobrescrever = true;
                    preencher('razao_social', d.razao_social, sobrescrever);
                    preencher('nome_fantasia', d.nome_fantasia, sobrescrever);
                    preencher('logradouro', d.logradouro, sobrescrever);
                    preencher('numero', d.numero, sobrescrever);
                    preencher('complemento', d.complemento, sobrescrever);
                    preencher('bairro', d.bairro, sobrescrever);
                    preencher('municipio', d.municipio, sobrescrever);
                    preencher('uf', d.uf, sobrescrever);
                    preencher('email', d.email, sobrescrever);
                    preencher('telefone', d.telefone, sobrescrever);
                    if (d.cep && (sobrescrever || !document.getElementById('cep').value)) {
                        document.getElementById('cep').value = d.cep.replace(/^(\d{5})(\d)/, '$1-$2');
                    }
                    if (d.codigo_municipio) document.getElementById('codigo_municipio').value = d.codigo_municipio;

                    if (d.ind_ie_dest) {
                        indicador.value = String(d.ind_ie_dest);
                        if (d.ie) document.getElementById('inscricao_estadual').value = d.ie;
                        atualizar();
                    }

                    const avisos = [];
                    if (!d.ativa) avisos.push(`Atenção: na Receita este CNPJ está "${d.situacao}".`);
                    avisos.push(d.ie_mensagem);
                    status.className = 'mt-1 text-xs ' + (d.ativa ? 'text-marca-700' : 'text-red-700');
                    status.textContent = 'Dados preenchidos. ' + avisos.join(' ');
                } catch (e) {
                    status.className = 'mt-1 text-xs text-amber-700';
                    status.textContent = 'Não foi possível consultar o CNPJ agora. Preencha os dados manualmente.';
                }
            }

            radios.forEach((r) => r.addEventListener('change', () => { doc.value = ''; ultimoCnpj = ''; atualizar(); doc.focus(); }));
            indicador.addEventListener('change', atualizar);
            doc.addEventListener('input', () => {
                mascara();
                const numeros = doc.value.replace(/\D/g, '');
                if (numeros.length === 14 && numeros !== ultimoCnpj) buscarCnpj(false);
            });
            atualizar();
        })();
    </script>
@endsection
