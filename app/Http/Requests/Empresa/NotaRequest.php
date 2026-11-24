<?php

namespace App\Http\Requests\Empresa;

use App\Models\Nota;
use App\Support\ChaveAcesso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->empresa !== null;
    }

    /** Aceita "1.234,56" e "1234,56" nos números digitados. */
    protected function prepareForValidation(): void
    {
        $numero = function ($valor) {
            $valor = trim((string) $valor);
            if ($valor === '') {
                return null;
            }

            return str_contains($valor, ',') ? str_replace(['.', ','], ['', '.'], $valor) : $valor;
        };

        $operacao = array_key_exists((string) $this->input('operacao'), Nota::OPERACOES) ? $this->input('operacao') : 'venda';

        // Item vale se tiver produto do cadastro ou (importado do XML) descrição própria
        $itens = array_values(array_filter(
            (array) $this->input('itens', []),
            fn ($i) => is_array($i) && (! empty($i['produto_id']) || trim((string) ($i['descricao'] ?? '')) !== '')
        ));

        foreach ($itens as &$item) {
            $item['quantidade'] = $numero($item['quantidade'] ?? null);
            $item['valor_unitario'] = $numero($item['valor_unitario'] ?? null);
            $item['desconto'] = $numero($item['desconto'] ?? null) ?? 0;
            $item['cfop'] = preg_replace('/\D/', '', (string) ($item['cfop'] ?? ''));
            foreach (['base_icms', 'aliq_icms', 'valor_icms', 'base_icms_st', 'aliq_icms_st', 'valor_icms_st', 'valor_ipi_devolvido'] as $campo) {
                $item[$campo] = $numero($item[$campo] ?? null) ?? 0;
            }
            $item['ncm'] = isset($item['ncm']) ? preg_replace('/\D/', '', (string) $item['ncm']) : null;
            $item['entrada'] = is_string($item['entrada'] ?? null) ? json_decode($item['entrada'], true) : ($item['entrada'] ?? null);
        }

        // Documentos referenciados: só na devolução
        $referencias = [];
        if ($operacao === 'devolucao') {
            foreach ((array) $this->input('referencias', []) as $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                if (($ref['tipo'] ?? 'nfe') === 'nf') {
                    if (collect($ref)->except('tipo')->filter()->isEmpty()) {
                        continue;
                    }
                    $referencias[] = [
                        'tipo' => 'nf',
                        'uf' => strtoupper((string) ($ref['uf'] ?? '')),
                        'mes_ano' => preg_replace('/[^\d\/]/', '', (string) ($ref['mes_ano'] ?? '')),
                        'cnpj' => preg_replace('/\D/', '', (string) ($ref['cnpj'] ?? '')),
                        'modelo' => str_pad(preg_replace('/\D/', '', (string) ($ref['modelo'] ?? '01')), 2, '0', STR_PAD_LEFT),
                        'serie' => preg_replace('/\D/', '', (string) ($ref['serie'] ?? '')),
                        'numero' => preg_replace('/\D/', '', (string) ($ref['numero'] ?? '')),
                    ];
                } elseif (($chave = ChaveAcesso::limpar($ref['chave'] ?? '')) !== '') {
                    $referencias[] = ['tipo' => 'nfe', 'chave' => $chave];
                }
            }
        }

        $this->merge([
            'operacao' => $operacao,
            'itens' => $itens,
            'referencias' => $referencias,
            // Só a venda tem pagamento
            'forma_pagamento' => Nota::OPERACOES[$operacao]['pagamento'] ? $this->input('forma_pagamento') : '90',
            'ind_pagamento' => Nota::OPERACOES[$operacao]['pagamento'] ? $this->input('ind_pagamento') : 0,
        ]);
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa->id;

        return [
            'operacao' => ['required', Rule::in(array_keys(Nota::OPERACOES))],
            'destinatario_id' => ['required', Rule::exists('destinatarios', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at')],
            'natureza_operacao' => ['nullable', 'string', 'max:60'],
            'consumidor_final' => ['nullable', 'boolean'],
            'modalidade_frete' => ['required', Rule::in(array_keys(Nota::MODALIDADES_FRETE))],
            'forma_pagamento' => ['required', Rule::in(array_keys(Nota::FORMAS_PAGAMENTO))],
            'ind_pagamento' => ['required', Rule::in([0, 1])],
            'descricao_pagamento' => ['required_if:forma_pagamento,99', 'nullable', 'string', 'max:60'],
            'informacoes_complementares' => ['nullable', 'string', 'max:2000'],

            'itens' => ['required', 'array', 'min:1', 'max:990'],
            'itens.*.produto_id' => ['nullable', Rule::exists('produtos', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at')],
            // Item importado do XML da nota de compra (sem cadastro de produto)
            'itens.*.codigo' => ['required_without:itens.*.produto_id', 'nullable', 'string', 'max:60'],
            'itens.*.descricao' => ['required_without:itens.*.produto_id', 'nullable', 'string', 'max:120'],
            'itens.*.ncm' => ['required_without:itens.*.produto_id', 'nullable', 'digits:8'],
            'itens.*.unidade' => ['required_without:itens.*.produto_id', 'nullable', 'string', 'max:6'],
            'itens.*.gtin' => ['nullable', 'string', 'max:14'],
            'itens.*.cest' => ['nullable', 'digits:7'],
            'itens.*.origem' => ['nullable', 'integer', 'between:0,8'],
            'itens.*.entrada' => ['nullable', 'array'],
            'itens.*.base_icms' => ['nullable', 'numeric', 'min:0'],
            'itens.*.aliq_icms' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'itens.*.valor_icms' => ['nullable', 'numeric', 'min:0'],
            'itens.*.base_icms_st' => ['nullable', 'numeric', 'min:0'],
            'itens.*.aliq_icms_st' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'itens.*.valor_icms_st' => ['nullable', 'numeric', 'min:0'],
            'itens.*.valor_ipi_devolvido' => ['nullable', 'numeric', 'min:0'],

            // NT 2025.002-RTC (regras VC02-14 e B25-40, desde 16/11/2026): a devolução referencia
            // UMA NF-e pela chave; nota em papel (modelo 1/1A) não é mais aceita
            'referencias' => ['array', 'max:1'],
            'referencias.*.tipo' => ['required', Rule::in(['nfe'])],
            'referencias.*.chave' => ['required_if:referencias.*.tipo,nfe', 'nullable', function (string $atributo, mixed $valor, \Closure $falha) {
                if ($valor && ! ChaveAcesso::valida($valor)) {
                    $falha('A chave de acesso da nota de compra é inválida. Confira os 44 números.');
                }
            }],
            'referencias.*.uf' => ['required_if:referencias.*.tipo,nf', 'nullable', 'size:2'],
            'referencias.*.mes_ano' => ['required_if:referencias.*.tipo,nf', 'nullable', 'date_format:m/Y'],
            'referencias.*.cnpj' => ['required_if:referencias.*.tipo,nf', 'nullable', 'digits:14'],
            'referencias.*.modelo' => ['required_if:referencias.*.tipo,nf', 'nullable', Rule::in(['01', '02'])],
            'referencias.*.serie' => ['required_if:referencias.*.tipo,nf', 'nullable', 'integer', 'between:0,999'],
            'referencias.*.numero' => ['required_if:referencias.*.tipo,nf', 'nullable', 'integer', 'between:1,999999999'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'itens.*.valor_unitario' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'itens.*.desconto' => ['nullable', 'numeric', 'min:0'],
            'itens.*.cfop' => ['nullable', 'digits:4'],
        ];
    }

    public function messages(): array
    {
        return [
            'itens.required' => 'Adicione pelo menos um produto à nota.',
            'itens.min' => 'Adicione pelo menos um produto à nota.',
            'descricao_pagamento.required_if' => 'Descreva a forma de pagamento.',
            'itens.*.quantidade.gt' => 'A quantidade deve ser maior que zero.',
            'itens.*.valor_unitario.gt' => 'O valor unitário deve ser maior que zero.',
            'itens.*.produto_id.exists' => 'Escolha um produto da lista.',
            'itens.*.descricao.required_without' => 'Escolha o produto de cada linha.',
            'referencias.*.chave.required_if' => 'Informe a chave de acesso da nota de compra.',
            'referencias.*.mes_ano.date_format' => 'Informe o mês e o ano da nota em papel como MM/AAAA.',
            'referencias.*.tipo.in' => 'A SEFAZ não aceita mais nota em papel (modelo 1/1A) como referência da devolução: informe a chave de uma NF-e.',
            'referencias.max' => 'Uma devolução pode referenciar só uma nota de compra. Emita uma devolução para cada compra.',
        ];
    }

    public function attributes(): array
    {
        return [
            'destinatario_id' => 'destinatário',
            'natureza_operacao' => 'natureza da operação',
            'modalidade_frete' => 'frete',
            'forma_pagamento' => 'forma de pagamento',
            'descricao_pagamento' => 'descrição do pagamento',
            'informacoes_complementares' => 'informações complementares',
            'itens.*.produto_id' => 'produto',
            'itens.*.quantidade' => 'quantidade',
            'itens.*.valor_unitario' => 'valor unitário',
            'itens.*.desconto' => 'desconto',
            'itens.*.cfop' => 'CFOP',
            'itens.*.ncm' => 'NCM',
            'referencias.*.uf' => 'UF da nota de compra',
            'referencias.*.cnpj' => 'CNPJ do emitente da nota de compra',
            'referencias.*.serie' => 'série da nota de compra',
            'referencias.*.numero' => 'número da nota de compra',
            'referencias.*.modelo' => 'modelo da nota de compra',
            'referencias.*.mes_ano' => 'mês/ano da nota de compra',
        ];
    }
}
