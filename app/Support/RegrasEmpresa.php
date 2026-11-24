<?php

namespace App\Support;

use App\Http\Requests\Empresa\AtualizarDadosRequest;
use App\Models\Empresa;
use App\Rules\InscricaoEstadual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Regras do cadastro de empresa emitente: usadas na criação da conta e quando o escritório cadastra um cliente. */
final class RegrasEmpresa
{
    public const CAMPOS = [
        'razao_social', 'nome_fantasia', 'cnpj', 'inscricao_estadual', 'crt', 'cnae', 'cep', 'logradouro',
        'numero', 'complemento', 'bairro', 'municipio', 'uf', 'codigo_municipio', 'telefone', 'email',
        'serie_nfe', 'proximo_numero_nfe',
    ];

    /** Deixa só os números e completa a numeração a partir da pergunta "já emitiu?". */
    public static function preparar(Request $request): void
    {
        $request->merge([
            'cep' => preg_replace('/\D/', '', (string) $request->input('cep')),
            'inscricao_estadual' => InscricaoEstadual::normalizar($request->input('inscricao_estadual'), $request->input('uf')) ?: null,
            'telefone' => preg_replace('/\D/', '', (string) $request->input('telefone')) ?: null,
            'uf' => strtoupper((string) $request->input('uf')),
            'codigo_municipio' => preg_replace('/\D/', '', (string) $request->input('codigo_municipio')) ?: null,
            'cnae' => preg_replace('/\D/', '', (string) $request->input('cnae')) ?: null,
        ] + NumeracaoNfe::doFormulario($request->all()));
    }

    public static function regras(): array
    {
        return [
            'razao_social' => ['required', 'string', 'min:2', 'max:60'],
            'nome_fantasia' => ['nullable', 'string', 'max:60'],
            'inscricao_estadual' => ['nullable', new InscricaoEstadual], // empresa só de serviços não tem
            'crt' => ['required', 'integer', Rule::in(array_keys(Empresa::REGIMES_DISPONIVEIS))],
            'cnae' => ['nullable', 'digits:7'],
            'cep' => ['required', 'digits:8'],
            'logradouro' => ['required', 'string', 'min:2', 'max:60'],
            'numero' => ['required', 'string', 'max:60'],
            'complemento' => ['nullable', 'string', 'max:60'],
            'bairro' => ['required', 'string', 'min:2', 'max:60'],
            'municipio' => ['required', 'string', 'min:2', 'max:60'],
            'uf' => ['required', Rule::in(AtualizarDadosRequest::UFS)],
            'codigo_municipio' => ['nullable', 'digits:7'],
            'telefone' => ['nullable', 'digits_between:10,11'],
            'serie_nfe' => ['required', 'integer', 'min:1', 'max:889'],
            'proximo_numero_nfe' => ['required', 'integer', 'min:1', 'max:999999999'],
        ];
    }

    public static function mensagens(): array
    {
        return [
            'cnpj.unique' => 'Este CNPJ já está cadastrado.',
            'crt.in' => 'Por enquanto o NotaPix atende apenas empresas do Simples Nacional e MEI.',
            'crt.required' => 'Escolha o regime tributário da empresa.',
            'telefone.digits_between' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'proximo_numero_nfe.required' => 'Informe o número da última nota emitida.',
        ];
    }

    public static function atributos(): array
    {
        return [
            'inscricao_estadual' => 'inscrição estadual', 'crt' => 'regime tributário', 'cep' => 'CEP',
            'logradouro' => 'rua', 'numero' => 'número', 'municipio' => 'cidade', 'uf' => 'UF',
        ];
    }
}
