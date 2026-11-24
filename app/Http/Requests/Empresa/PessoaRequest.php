<?php

namespace App\Http\Requests\Empresa;

use App\Models\Destinatario;
use App\Rules\Cnpj;
use App\Rules\Cpf;
use App\Rules\InscricaoEstadual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PessoaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->empresa !== null;
    }

    protected function prepareForValidation(): void
    {
        $fisica = $this->input('tipo_pessoa') === Destinatario::FISICA;

        $this->merge([
            'cpf_cnpj' => preg_replace('/\D/', '', (string) $this->input('cpf_cnpj')),
            'cep' => preg_replace('/\D/', '', (string) $this->input('cep')),
            'telefone' => preg_replace('/\D/', '', (string) $this->input('telefone')) ?: null,
            'uf' => strtoupper((string) $this->input('uf')),
            'codigo_municipio' => preg_replace('/\D/', '', (string) $this->input('codigo_municipio')) ?: null,
            // Pessoa física: sempre não contribuinte, sem IE e sem nome fantasia
            'ind_ie_dest' => $fisica ? Destinatario::IE_NAO_CONTRIBUINTE : (int) $this->input('ind_ie_dest'),
            'inscricao_estadual' => $fisica ? null : \App\Rules\InscricaoEstadual::normalizar($this->input('inscricao_estadual'), $this->input('uf')),
            'nome_fantasia' => $fisica ? null : $this->input('nome_fantasia'),
        ]);
    }

    public function rules(): array
    {
        $fisica = $this->input('tipo_pessoa') === Destinatario::FISICA;
        $contribuinte = (int) $this->input('ind_ie_dest') === Destinatario::IE_CONTRIBUINTE;

        return [
            'tipo_pessoa' => ['required', Rule::in([Destinatario::FISICA, Destinatario::JURIDICA])],
            'cpf_cnpj' => ['required', $fisica ? new Cpf : new Cnpj],
            'razao_social' => ['required', 'string', 'min:2', 'max:60'],
            'nome_fantasia' => ['nullable', 'string', 'max:60'],
            'ind_ie_dest' => ['required', Rule::in(array_keys(Destinatario::INDICADORES_IE))],
            'inscricao_estadual' => $contribuinte
                ? ['required', new InscricaoEstadual]
                : ['nullable', 'prohibited_unless:ind_ie_dest,'.Destinatario::IE_CONTRIBUINTE],

            'cep' => ['required', 'digits:8'],
            'logradouro' => ['required', 'string', 'min:2', 'max:60'],
            'numero' => ['required', 'string', 'max:60'],
            'complemento' => ['nullable', 'string', 'max:60'],
            'bairro' => ['required', 'string', 'min:2', 'max:60'],
            'municipio' => ['required', 'string', 'min:2', 'max:60'],
            'uf' => ['required', Rule::in(AtualizarDadosRequest::UFS)],
            'codigo_municipio' => ['nullable', 'digits:7'],

            'email' => ['nullable', 'email', 'max:150'],
            'telefone' => ['nullable', 'digits_between:10,11'],
        ];
    }

    public function messages(): array
    {
        return [
            'inscricao_estadual.required' => 'Informe a inscrição estadual, ou escolha "Isento" ou "Não contribuinte".',
            'inscricao_estadual.prohibited_unless' => 'Só informe a inscrição estadual para contribuintes do ICMS.',
            'telefone.digits_between' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'cep.digits' => 'O CEP deve ter 8 dígitos.',
        ];
    }

    public function attributes(): array
    {
        $fisica = $this->input('tipo_pessoa') === Destinatario::FISICA;

        return [
            'cpf_cnpj' => $fisica ? 'CPF' : 'CNPJ',
            'razao_social' => $fisica ? 'nome' : 'razão social',
            'nome_fantasia' => 'nome fantasia',
            'ind_ie_dest' => 'situação da inscrição estadual',
            'inscricao_estadual' => 'inscrição estadual',
            'cep' => 'CEP',
            'logradouro' => 'rua',
            'numero' => 'número',
            'municipio' => 'cidade',
            'uf' => 'UF',
        ];
    }
}
