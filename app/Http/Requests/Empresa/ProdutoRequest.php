<?php

namespace App\Http\Requests\Empresa;

use App\Models\Produto;
use App\Rules\CfopSaidaValido;
use App\Rules\Gtin;
use App\Rules\NcmValido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProdutoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->empresa !== null;
    }

    protected function prepareForValidation(): void
    {
        $gtin = preg_replace('/\D/', '', (string) $this->input('gtin'));

        $this->merge([
            'descricao' => trim(preg_replace('/\s+/', ' ', (string) $this->input('descricao'))),
            'gtin' => $gtin === '' ? 'SEM GTIN' : $gtin,
            'ncm' => preg_replace('/\D/', '', (string) $this->input('ncm')),
            'cest' => preg_replace('/\D/', '', (string) $this->input('cest')) ?: null,
            'unidade' => strtoupper(trim((string) $this->input('unidade'))),
            'valor_unitario' => $this->numero($this->input('valor_unitario')),
            'cfop_padrao' => preg_replace('/\D/', '', (string) $this->input('cfop_padrao')),
            'cclass_trib' => $cclass = (preg_replace('/\D/', '', (string) $this->input('cclass_trib')) ?: null),
            // O CST são os 3 primeiros dígitos da classificação: não é digitado
            'cst_ibs_cbs' => $cclass && strlen($cclass) === 6 ? substr($cclass, 0, 3) : null,
        ]);
    }

    /** Aceita "1.234,56", "1234,56" e "1234.56". */
    private function numero(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        if (str_contains($valor, ',')) {
            $valor = str_replace(['.', ','], ['', '.'], $valor);
        }

        return $valor;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'min:2', 'max:120'],
            'gtin' => ['required', new Gtin],
            'ncm' => ['required', new NcmValido],
            'cest' => ['nullable', 'digits:7'],
            'unidade' => ['required', Rule::in(array_keys(Produto::UNIDADES))],
            'valor_unitario' => ['required', 'numeric', 'gt:0', 'max:9999999999'],

            'origem' => ['required', 'integer', Rule::in(array_keys(Produto::ORIGENS))],
            'cfop_padrao' => ['required', new CfopSaidaValido],
            'cst_csosn' => ['required', Rule::in(array_keys(Produto::CSOSN))],
            'cst_pis' => ['required', Rule::in(array_keys(Produto::CST_PIS_COFINS))],
            'cst_cofins' => ['required', Rule::in(array_keys(Produto::CST_PIS_COFINS))],

            // Reforma tributária: opcionais por enquanto
            'cst_ibs_cbs' => ['nullable', 'digits:3'],
            'cclass_trib' => ['nullable', 'digits:6', function (string $atributo, mixed $valor, \Closure $falha) {
                if (! \App\Models\ClassTrib::query()->paraNfe()->whereKey($valor)->exists()) {
                    $falha('Classificação tributária não encontrada, fora de vigência ou não permitida na NF-e. Use a pesquisa.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'unidade.in' => 'Escolha uma unidade da lista.',
            'valor_unitario.gt' => 'Informe o preço de venda.',
            'cest.digits' => 'O CEST deve ter 7 dígitos.',
        ];
    }

    public function attributes(): array
    {
        return [
            'codigo' => 'código',
            'descricao' => 'descrição',
            'gtin' => 'código de barras',
            'ncm' => 'NCM',
            'cest' => 'CEST',
            'valor_unitario' => 'preço',
            'cfop_padrao' => 'CFOP',
            'cst_csosn' => 'CSOSN',
            'cst_pis' => 'CST do PIS',
            'cst_cofins' => 'CST da COFINS',
            'cst_ibs_cbs' => 'CST do IBS/CBS',
            'cclass_trib' => 'classificação tributária',
        ];
    }
}
