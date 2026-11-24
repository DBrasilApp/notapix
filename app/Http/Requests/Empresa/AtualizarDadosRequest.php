<?php

namespace App\Http\Requests\Empresa;

use App\Models\Empresa;
use App\Rules\Cnpj;
use App\Rules\InscricaoEstadual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtualizarDadosRequest extends FormRequest
{
    public const UFS = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA',
        'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    public function authorize(): bool
    {
        return $this->user()?->empresa !== null;
    }

    /** Guarda só os números de CEP, IE e telefone; UF em maiúsculas. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cnpj' => $this->has('cnpj') ? preg_replace('/\D/', '', (string) $this->input('cnpj')) : null,
            'cep' => preg_replace('/\D/', '', (string) $this->input('cep')),
            'inscricao_estadual' => \App\Rules\InscricaoEstadual::normalizar($this->input('inscricao_estadual'), $this->input('uf')) ?: null,
            'telefone' => preg_replace('/\D/', '', (string) $this->input('telefone')) ?: null,
            'uf' => strtoupper((string) $this->input('uf')),
            'codigo_municipio' => preg_replace('/\D/', '', (string) $this->input('codigo_municipio')) ?: null,
            'cnae' => preg_replace('/\D/', '', (string) $this->input('cnae')) ?: null,
        ] + \App\Support\NumeracaoNfe::doFormulario($this->all()));

        // NFC-e: a tela pede a última NFC-e emitida (0 = nunca emitiu)
        if ($this->filled('nfce_ultimo_numero')) {
            $this->merge(['nfce_proximo_numero' => (int) $this->input('nfce_ultimo_numero') + 1]);
        }
        // NFS-e: a tela pede o número da última DPS enviada pelo NotaPix nesta série (0 = nenhuma)
        if ($this->filled('nfse_ultimo_numero')) {
            $this->merge(['nfse_proximo_numero' => (int) $this->input('nfse_ultimo_numero') + 1]);
        }
        if ($this->has('nfse_serie')) {
            $this->merge([
                'inscricao_municipal' => preg_replace('/\D/', '', (string) $this->input('inscricao_municipal')) ?: null,
                'nfse_serie' => ltrim(preg_replace('/\D/', '', (string) $this->input('nfse_serie')), '0') ?: null,
                'nfse_aliquota_simples' => \App\Support\NumeroBr::paraBanco($this->input('nfse_aliquota_simples')),
                'nfse_aliquota_iss' => \App\Support\NumeroBr::paraBanco($this->input('nfse_aliquota_iss')),
            ]);
        }
        foreach (['nfce_csc_homologacao', 'nfce_csc_producao'] as $campo) {
            $this->merge([$campo => strtoupper(preg_replace('/\s+/', '', (string) $this->input($campo))) ?: null]);
        }
        foreach (['nfce_csc_id_homologacao', 'nfce_csc_id_producao'] as $campo) {
            $this->merge([$campo => preg_replace('/\D/', '', (string) $this->input($campo)) ?: null]);
        }
    }

    public function rules(): array
    {
        // Tamanhos máximos seguem o leiaute da NF-e
        return [
            'cnpj' => $this->user()?->empresa?->podeAlterarCnpj()
                ? ['nullable', 'digits:14', new Cnpj, Rule::unique('empresas', 'cnpj')->ignore($this->user()->empresa->id)]
                : ['prohibited'],
            'razao_social' => ['required', 'string', 'min:2', 'max:60'],
            'nome_fantasia' => ['nullable', 'string', 'max:60'],
            'inscricao_estadual' => ['nullable', new InscricaoEstadual], // empresa só de serviços não tem
            'crt' => ['required', 'integer', Rule::in(array_keys(Empresa::REGIMES_DISPONIVEIS))],

            'cep' => ['required', 'digits:8'],
            'logradouro' => ['required', 'string', 'min:2', 'max:60'],
            'numero' => ['required', 'string', 'max:60'],
            'complemento' => ['nullable', 'string', 'max:60'],
            'bairro' => ['required', 'string', 'min:2', 'max:60'],
            'municipio' => ['required', 'string', 'min:2', 'max:60'],
            'uf' => ['required', Rule::in(self::UFS)],
            'codigo_municipio' => ['nullable', 'digits:7'],

            'email' => ['required', 'email', 'max:150'],
            'telefone' => ['nullable', 'digits_between:10,11'],

            'cnae' => ['nullable', 'digits:7'],
            'serie_nfe' => ['required', 'integer', 'min:1', 'max:889'],
            'proximo_numero_nfe' => ['required', 'integer', 'min:1', 'max:999999999'],

            // NFC-e (opcional). CSC em branco = mantém o que já está guardado
            'nfce_csc_homologacao' => ['nullable', 'string', 'between:16,36', 'alpha_num'],
            'nfce_csc_id_homologacao' => ['nullable', 'digits_between:1,6'],
            'nfce_csc_producao' => ['nullable', 'string', 'between:16,36', 'alpha_num'],
            'nfce_csc_id_producao' => ['nullable', 'digits_between:1,6'],
            'nfce_serie' => ['sometimes', 'integer', 'min:1', 'max:889'],
            'nfce_proximo_numero' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'nfce_bobina' => ['sometimes', Rule::in([58, 72, 80])],

            // NFS-e (opcional)
            'inscricao_municipal' => ['sometimes', 'nullable', 'digits_between:1,15'],
            'nfse_serie' => ['sometimes', 'required', 'integer', 'min:1', 'max:89999'],
            'nfse_proximo_numero' => ['sometimes', 'integer', 'min:1', 'max:999999999999999'],
            'nfse_regime_apuracao' => ['sometimes', Rule::in([1, 2, 3])],
            'nfse_aliquota_simples' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:40'],
            'nfse_aliquota_iss' => ['sometimes', 'nullable', 'numeric', 'min:2', 'max:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'crt.in' => 'Por enquanto o sistema atende apenas empresas do Simples Nacional e MEI.',
            'telefone.digits_between' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'cep.digits' => 'O CEP deve ter 8 dígitos.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado em outra conta.',
            'cnpj.prohibited' => 'O CNPJ não pode mais ser alterado porque a empresa já emitiu notas.',
            'proximo_numero_nfe.required' => 'Informe o número da última nota emitida.',
            'proximo_numero_nfe.min' => 'Informe o número da última nota emitida.',
            'nfce_csc_homologacao.between' => 'O CSC tem 16 a 36 letras e números (normalmente 32). Confira o código enviado pela SEFAZ.',
            'nfse_aliquota_iss.min' => 'A alíquota do ISS fica entre 2% e 5%.',
            'nfse_aliquota_iss.max' => 'A alíquota do ISS fica entre 2% e 5%.',
            'nfce_csc_producao.between' => 'O CSC tem 16 a 36 letras e números (normalmente 32). Confira o código enviado pela SEFAZ.',
        ];
    }

    public function attributes(): array
    {
        return [
            'cnpj' => 'CNPJ',
            'nome_fantasia' => 'nome fantasia',
            'inscricao_estadual' => 'inscrição estadual',
            'crt' => 'regime tributário',
            'cep' => 'CEP',
            'logradouro' => 'rua',
            'numero' => 'número',
            'municipio' => 'cidade',
            'uf' => 'UF',
            'telefone' => 'telefone',
            'serie_nfe' => 'série',
            'nfce_csc_homologacao' => 'CSC de homologação',
            'nfce_csc_producao' => 'CSC de produção',
            'nfce_csc_id_homologacao' => 'ID do CSC de homologação',
            'nfce_csc_id_producao' => 'ID do CSC de produção',
            'nfce_serie' => 'série da NFC-e',
            'proximo_numero_nfe' => 'próximo número',
            'inscricao_municipal' => 'inscrição municipal',
            'nfse_serie' => 'série da DPS',
            'nfse_aliquota_simples' => '% aproximado de tributos',
            'nfse_aliquota_iss' => 'alíquota do ISS',
        ];
    }
}
