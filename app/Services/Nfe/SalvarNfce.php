<?php

namespace App\Services\Nfe;

use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use App\Rules\Cnpj;
use App\Rules\Cpf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava a venda do caixa como NFC-e (modelo 65): consumidor final, presencial, dentro do estado.
 * Cria nova ou, se a NFC-e foi rejeitada, refaz a mesma (o número continua reservado).
 */
class SalvarNfce
{
    /** CFOPs aceitos na NFC-e (vendas e algumas saídas a consumidor). */
    public const CFOPS = ['5101', '5102', '5103', '5104', '5115', '5405', '5656', '5667', '5933'];

    /** Formas de pagamento do caixa. */
    public const PAGAMENTOS = [
        '01' => 'Dinheiro',
        '17' => 'Pix',
        '04' => 'Cartão de débito',
        '03' => 'Cartão de crédito',
        '05' => 'Crédito loja',
        '99' => 'Outros',
    ];

    public function salvar(Empresa $empresa, User $usuario, array $dados, ?Nota $nota = null): Nota
    {
        $produtos = $empresa->produtos()->whereIn('id', array_column($dados['itens'], 'produto_id'))->get()->keyBy('id');
        $erros = [];
        $itens = [];

        foreach (array_values($dados['itens']) as $i => $linha) {
            $produto = $produtos->get($linha['produto_id']);
            if (! $produto) {
                $erros["itens.$i.produto_id"] = 'Produto não encontrado.';
                continue;
            }

            // Venda no balcão é sempre dentro do estado: 6.xxx vira 5.xxx
            $cfop = preg_replace('/^6/', '5', (string) $produto->cfop_padrao);
            if (! in_array($cfop, self::CFOPS, true)) {
                $erros["itens.$i.produto_id"] = "{$produto->descricao}: o CFOP ".\App\Models\Cfop::formatar($cfop)
                    .' do produto não pode ser usado na NFC-e. Use 5.102 (revenda), 5.101 (fabricação própria) ou 5.405 (com ST) no cadastro do produto.';
                continue;
            }

            $quantidade = round((float) $linha['quantidade'], 4);
            $unitario = round((float) ($linha['valor_unitario'] ?? $produto->valor_unitario), 2);
            $bruto = round($quantidade * $unitario, 2);
            $desconto = round((float) ($linha['desconto'] ?? 0), 2);
            if ($desconto > 0 && $desconto >= $bruto) {
                $erros["itens.$i.desconto"] = "{$produto->descricao}: o desconto deve ser menor que o total do item.";
            }

            $itens[] = [
                'numero_item' => $i + 1,
                'produto_id' => $produto->id,
                'codigo' => $produto->codigo,
                'descricao' => $produto->descricao,
                'gtin' => $produto->gtin ?: 'SEM GTIN',
                'ncm' => $produto->ncm,
                'cest' => $produto->cest,
                'cfop' => $cfop,
                'unidade' => $produto->unidade,
                'quantidade' => $quantidade,
                'valor_unitario' => $unitario,
                'valor_total' => $bruto,
                'valor_desconto' => $desconto,
                'origem' => $produto->origem,
                'cst_csosn' => $produto->cst_csosn,
                'cst_pis' => $produto->cst_pis,
                'cst_cofins' => $produto->cst_cofins,
                'cst_ibs_cbs' => $produto->cst_ibs_cbs,
                'cclass_trib' => $produto->cclass_trib,
            ];
        }

        $total = round(array_sum(array_column($itens, 'valor_total')) - array_sum(array_column($itens, 'valor_desconto')), 2);

        // Pagamentos: a soma cobre o total; o que passar só pode ser troco do dinheiro
        $pagamentos = array_values(array_filter($dados['pagamentos'], fn ($p) => (float) $p['valor'] > 0));
        $pago = round(array_sum(array_column($pagamentos, 'valor')), 2);
        $dinheiro = round(array_sum(array_map(fn ($p) => $p['forma'] === '01' ? (float) $p['valor'] : 0, $pagamentos)), 2);
        $troco = round(max(0, $pago - $total), 2);

        if (! $pagamentos) {
            $erros['pagamentos'] = 'Informe como o cliente vai pagar.';
        } elseif ($pago + 0.001 < $total) {
            $erros['pagamentos'] = 'Falta receber R$ '.number_format($total - $pago, 2, ',', '.').'.';
        } elseif ($troco > 0 && $troco > $dinheiro + 0.001) {
            $erros['pagamentos'] = 'O valor pago passa do total, mas só dá troco quando há pagamento em dinheiro. Confira os valores.';
        }

        $documento = preg_replace('/\D/', '', (string) ($dados['cpf_cnpj'] ?? ''));
        if ($documento !== '' && ! (strlen($documento) === 11 ? Cpf::valido($documento) : Cnpj::valido($documento))) {
            $erros['cpf_cnpj'] = 'CPF ou CNPJ do consumidor inválido.';
        }

        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        $cabecalho = [
            'destinatario_id' => null,
            'destinatario_dados' => $documento !== '' ? [
                'cpf_cnpj' => $documento,
                'razao_social' => trim((string) ($dados['nome'] ?? '')) ?: 'Consumidor',
            ] : ['razao_social' => 'Consumidor não identificado'],
            'user_id' => $usuario->id,
            'modelo' => 65,
            'operacao' => 'venda',
            'referencias' => null,
            'natureza_operacao' => 'Venda',
            'tipo_operacao' => 1,
            'finalidade' => 1,
            'id_dest' => 1,
            'ind_final' => 1,
            'ind_pres' => 1,
            'modalidade_frete' => 9,
            'forma_pagamento' => $pagamentos[0]['forma'] ?? '01',
            'ind_pagamento' => 0,
            'pagamentos' => array_map(fn ($p) => ['forma' => $p['forma'], 'valor' => round((float) $p['valor'], 2)], $pagamentos),
            'troco' => $troco,
            'valor_produtos' => round(array_sum(array_column($itens, 'valor_total')), 2),
            'valor_desconto' => round(array_sum(array_column($itens, 'valor_desconto')), 2),
            'valor_total' => $total,
        ];

        return DB::transaction(function () use ($empresa, $cabecalho, $itens, $nota) {
            if ($nota) {
                $nota->update($cabecalho);
                $nota->itens()->delete();
            } else {
                $nota = $empresa->notas()->create($cabecalho + ['status' => Nota::RASCUNHO]);
            }

            $nota->itens()->createMany($itens);

            return $nota->load('itens');
        });
    }
}
