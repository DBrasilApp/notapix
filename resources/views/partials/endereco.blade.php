{{--
    Campos de endereço com preenchimento pelo CEP (ViaCEP).
    Uso: @include('partials.endereco', ['registro' => $empresa, 'ufs' => $ufs])
--}}
<div class="grid gap-4 sm:grid-cols-6">
    <div class="sm:col-span-2">
        <x-campo label="CEP" name="cep" :value="$registro->cep" required inputmode="numeric" maxlength="9" placeholder="00000-000" />
        <p id="cep-status" class="mt-1 text-xs text-slate-500" aria-live="polite"></p>
    </div>
    <div class="sm:col-span-4">
        <x-campo label="Rua" name="logradouro" :value="$registro->logradouro" required maxlength="60" />
    </div>

    <div class="sm:col-span-2">
        <x-campo label="Número" name="numero" :value="$registro->numero" required maxlength="60" ajuda="Sem número? Use S/N." />
    </div>
    <div class="sm:col-span-4">
        <x-campo label="Complemento (opcional)" name="complemento" :value="$registro->complemento" maxlength="60" />
    </div>

    <div class="sm:col-span-2">
        <x-campo label="Bairro" name="bairro" :value="$registro->bairro" required maxlength="60" />
    </div>
    <div class="sm:col-span-3">
        <x-campo label="Cidade" name="municipio" :value="$registro->municipio" required maxlength="60" />
    </div>
    <div class="sm:col-span-1">
        <x-selecao label="UF" name="uf" required>
            @foreach ($ufs as $uf)
                <option value="{{ $uf }}" @selected(old('uf', $registro->uf ?: config('nfeexpress.uf_padrao')) === $uf)>{{ $uf }}</option>
            @endforeach
        </x-selecao>
    </div>
</div>

{{-- Código IBGE do município: vem com o CEP, é exigido na NF-e --}}
<input type="hidden" name="codigo_municipio" id="codigo_municipio" value="{{ old('codigo_municipio', $registro->codigo_municipio) }}">

<script>
    (() => {
        const campo = (id) => document.getElementById(id);
        const cep = campo('cep');
        const status = campo('cep-status');
        const mascara = (v) => v.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');
        let ultimoBuscado = cep.value.replace(/\D/g, '');

        cep.value = mascara(cep.value);

        cep.addEventListener('input', () => {
            cep.value = mascara(cep.value);
            const numeros = cep.value.replace(/\D/g, '');
            if (numeros.length === 8 && numeros !== ultimoBuscado) buscar(numeros);
        });

        // Cidade alterada à mão: o código IBGE antigo deixa de valer
        ['municipio', 'uf'].forEach((id) => campo(id).addEventListener('change', () => {
            campo('codigo_municipio').value = '';
        }));

        async function buscar(numeros) {
            ultimoBuscado = numeros;
            status.textContent = 'Buscando endereço...';

            try {
                const dados = await (await fetch(`https://viacep.com.br/ws/${numeros}/json/`)).json();

                if (dados.erro) {
                    status.textContent = 'CEP não encontrado. Preencha o endereço manualmente.';
                    return;
                }

                if (dados.logradouro) campo('logradouro').value = dados.logradouro;
                if (dados.bairro) campo('bairro').value = dados.bairro;
                campo('municipio').value = dados.localidade;
                campo('uf').value = dados.uf;
                campo('codigo_municipio').value = dados.ibge;

                if (dados.logradouro) {
                    status.textContent = 'Endereço encontrado. Confira e informe o número.';
                    campo('numero').focus();
                } else {
                    status.textContent = 'Este CEP é geral da cidade. Informe a rua e o bairro.';
                    campo('logradouro').focus();
                }
            } catch (e) {
                status.textContent = 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.';
            }
        }
    })();
</script>
