<?php

namespace App\Http\Requests\Empresa;

use App\Support\NumeroBr;
use App\Support\TabelaServicosNacional;
use Illuminate\Foundation\Http\FormRequest;

class ServicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->empresa !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => trim(preg_replace('/\s+/', ' ', (string) $this->input('nome'))),
            'ctribnac' => TabelaServicosNacional::limpar($this->input('ctribnac')),
            'ctribmun' => preg_replace('/\D/', '', (string) $this->input('ctribmun')) ?: null,
            'cnbs' => preg_replace('/\D/', '', (string) $this->input('cnbs')) ?: null,
            'discriminacao' => trim((string) $this->input('discriminacao')),
            'valor' => NumeroBr::paraBanco($this->input('valor')),
            'iss_retido' => $this->boolean('iss_retido'),
        ]);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'ctribnac' => ['required', 'digits:6', function ($attr, $valor, $fail) {
                if (! TabelaServicosNacional::existe($valor)) {
                    $fail('Este código não está na lista nacional de serviços. Use a pesquisa.');
                }
            }],
            'ctribmun' => ['nullable', 'digits:3'],
            'cnbs' => ['nullable', 'digits:9'],
            'discriminacao' => ['required', 'string', 'max:2000'],
            'valor' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'iss_retido' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ctribnac.required' => 'Escolha o código do serviço na lista nacional.',
            'ctribnac.digits' => 'O código da lista nacional tem 6 dígitos (ex.: 01.07.01).',
            'ctribmun.digits' => 'O código municipal tem 3 dígitos (ex.: 001).',
            'cnbs.digits' => 'O código NBS tem 9 dígitos.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nome' => 'nome do serviço',
            'discriminacao' => 'descrição que vai na nota',
            'valor' => 'valor',
        ];
    }
}
