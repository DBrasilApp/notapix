<?php

namespace App\Services\Nfe;

use App\Models\Cfop;
use App\Models\Destinatario;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava a nota (rascunho) a partir do formulário.
 * Os dados do destinatário e dos produtos são COPIADOS para a nota: se o cadastro
 * mudar depois, a nota continua com o que foi emitido.
 */
class SalvarNota
{
    public function salvar(Empresa $empresa, User $usuario, array $dados, ?Nota $nota = null): Nota
    {
        /** @var Destinatario $pessoa */
        $pessoa = $empresa->destinatarios()->findOrFail($dados['destinatario_id']);

        $interestadual = strtoupper((string) $pessoa->uf) !== strtoupper((string) $empresa->uf);
        $naoContribuinte = $pessoa->ind_ie_dest === Destinatario::IE_NAO_CONTRIBUINTE;

        $operacao = array_key_exists($dados['operacao'] ?? 'venda', Nota::OPERACOES) ? ($dados['operacao'] ?? 'venda') : 'venda';
        $config = Nota::OPERACOES[$operacao];
        $devolucao = $operacao === 'devolucao';

        $produtos = $empresa->produtos()->whereIn('id', array_filter(array_column($dados['itens'], 'produto_id')))->get()->keyBy('id');
        $cfops = Cfop::query()->saidasNfe()->pluck('ind_devolucao', 'codigo');

        $itens = [];
        $erros = [];

        if ($devolucao && empty($dados['referencias'])) {
            $erros['referencias'] = 'Informe a nota de compra que está sendo devolvida (chave de acesso ou dados da nota em papel).';
        }

        foreach (array_values($dados['itens']) as $i => $linha) {
            $produto = ! empty($linha['produto_id']) ? $produtos->get($linha['produto_id']) : null;

            if (! empty($linha['produto_id']) && ! $produto) {
                $erros["itens.$i.produto_id"] = 'Produto não encontrado.';
                continue;
            }
            if (! $produto && ! $devolucao) {
                $erros["itens.$i.produto_id"] = 'Escolha um produto do seu cadastro em cada linha.';
                continue;
            }

            $quantidade = round((float) $linha['quantidade'], 4);
            $unitario = round((float) $linha['valor_unitario'], 4);
            $desconto = round((float) ($linha['desconto'] ?? 0), 2);

            // Item importado da nota de compra: preço e desconto vêm da compra (desconto proporcional)
            $entrada = $devolucao && is_array($linha['entrada'] ?? null) ? $linha['entrada'] : null;
            if ($entrada && ! (is_numeric($entrada['quantidade'] ?? null) && (float) $entrada['quantidade'] > 0 && is_numeric($entrada['valor_unitario'] ?? null))) {
                $erros["itens.$i.quantidade"] = 'Os dados da nota de compra deste item estão incompletos. Importe o XML de novo.';
                continue;
            }
            if ($entrada) {
                $unitario = round((float) $entrada['valor_unitario'], 4);
                $fator = (float) $entrada['quantidade'] > 0 ? min(1, $quantidade / (float) $entrada['quantidade']) : 1;
                $desconto = round((float) ($entrada['valor_desconto'] ?? 0) * $fator, 2);
            }
            $bruto = round($quantidade * $unitario, 2);

            if ($desconto > 0 && $desconto >= $bruto) {
                $erros["itens.$i.desconto"] = 'O desconto deve ser menor que o total do item.';
            }

            // CFOP: o digitado; se vazio, o da operação; se ainda vazio, o do produto
            $cfop = preg_replace('/\D/', '', (string) ($linha['cfop'] ?? '')) ?: ($config['cfop'] ?? $produto?->cfop_padrao ?? '');
            $cfop = $this->cfopParaOperacao($cfop, $interestadual);

            if (! $cfops->has($cfop)) {
                $erros["itens.$i.cfop"] = "O CFOP {$cfop} não existe ou não pode ser usado nesta operação.";
            } elseif ($devolucao && ! $cfops[$cfop]) {
                $erros["itens.$i.cfop"] = 'Na devolução de compra use um CFOP de devolução (ex.: '.Cfop::formatar($interestadual ? '6202' : '5202').', ou '.Cfop::formatar($interestadual ? '6411' : '5411').' se a compra teve substituição tributária).';
            } elseif (! $devolucao && $cfops[$cfop]) {
                $erros["itens.$i.cfop"] = 'O CFOP '.Cfop::formatar($cfop).' é de devolução: escolha a operação "Devolução de compra".';
            }

            $impostos = $devolucao ? $this->impostosDevolucao(['entrada' => $entrada] + $linha, $quantidade, $i, $erros) : [];

            $itens[] = array_merge(($produto ? $this->copiarProduto($produto) : $this->copiarItemAvulso($linha)) + [
                'numero_item' => $i + 1,
                'produto_id' => $produto?->id,
                'cfop' => $cfop,
                'quantidade' => $quantidade,
                'valor_unitario' => $unitario,
                'valor_total' => $bruto,
                'valor_desconto' => $desconto,
            ], $impostos);
        }

        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        $soma = fn (string $campo) => round(array_sum(array_column($itens, $campo)), 2);
        $totalProdutos = $soma('valor_total');
        $totalDesconto = $soma('valor_desconto');
        $totalSt = $devolucao ? $soma('valor_icms_st') : 0;
        $totalIpiDevolvido = $devolucao ? $soma('valor_ipi_devolvido') : 0;

        $natureza = trim((string) ($dados['natureza_operacao'] ?? '')) ?: $config['natureza'];

        $cabecalho = [
            'destinatario_id' => $pessoa->id,
            'destinatario_dados' => $this->copiarPessoa($pessoa),
            'user_id' => $usuario->id,
            'modelo' => 55,
            'operacao' => $operacao,
            'referencias' => $devolucao ? array_values($dados['referencias']) : null,
            'natureza_operacao' => mb_substr($natureza, 0, 60),
            'tipo_operacao' => 1,
            'finalidade' => $config['finalidade'],
            'id_dest' => $interestadual ? 2 : 1,
            // "Consumidor final" marcado só faz sentido na venda
            'ind_final' => ($pessoa->isFisica() || $naoContribuinte || ($operacao === 'venda' && ! empty($dados['consumidor_final']))) ? 1 : 0,
            'ind_pres' => $operacao === 'venda' ? (int) ($dados['ind_pres'] ?? 1) : 9,
            'modalidade_frete' => (int) $dados['modalidade_frete'],
            'forma_pagamento' => $config['pagamento'] ? $dados['forma_pagamento'] : '90',
            'ind_pagamento' => $config['pagamento'] ? (int) ($dados['ind_pagamento'] ?? 0) : 0,
            'descricao_pagamento' => $config['pagamento'] && $dados['forma_pagamento'] === '99' ? ($dados['descricao_pagamento'] ?? null) : null,
            'informacoes_complementares' => $dados['informacoes_complementares'] ?? null,
            'valor_produtos' => $totalProdutos,
            'valor_desconto' => $totalDesconto,
            'valor_icms' => $devolucao ? $soma('valor_icms') : 0,
            'valor_icms_st' => $totalSt,
            'valor_ipi_devolvido' => $totalIpiDevolvido,
            'valor_total' => round($totalProdutos - $totalDesconto + $totalSt + $totalIpiDevolvido, 2),
        ];

        return DB::transaction(function () use ($empresa, $nota, $cabecalho, $itens) {
            if ($nota) {
                $nota->update($cabecalho);
                $nota->itens()->delete();
            } else {
                $nota = $empresa->notas()->create($cabecalho + [
                    'status' => Nota::RASCUNHO,
                    'ambiente' => $empresa->ambiente ?? Empresa::AMBIENTE_HOMOLOGACAO,
                    'serie' => $empresa->serie_nfe ?? 1,
                ]);
            }

            $nota->itens()->createMany($itens);

            return $nota->fresh('itens');
        });
    }

    /** Para cliente de outro estado, 5.xxx vira 6.xxx (e o inverso). */
    private function cfopParaOperacao(string $cfop, bool $interestadual): string
    {
        if ($interestadual && str_starts_with($cfop, '5')) {
            return '6'.substr($cfop, 1);
        }
        if (! $interestadual && str_starts_with($cfop, '6')) {
            return '5'.substr($cfop, 1);
        }

        return $cfop;
    }

    private function copiarProduto(Produto $p): array
    {
        return [
            'codigo' => $p->codigo,
            'descricao' => $p->descricao,
            'gtin' => $p->gtin ?: 'SEM GTIN',
            'ncm' => $p->ncm,
            'cest' => $p->cest,
            'unidade' => $p->unidade,
            'origem' => $p->origem,
            'cst_csosn' => $p->cst_csosn,
            'cst_pis' => $p->cst_pis,
            'cst_cofins' => $p->cst_cofins,
            'cst_ibs_cbs' => $p->cst_ibs_cbs,
            'cclass_trib' => $p->cclass_trib,
        ];
    }

    /**
     * Impostos da nota de compra que voltam destacados na devolução (CSOSN 900).
     * Item importado do XML: proporcionais à quantidade devolvida. Item digitado: como informados.
     */
    private function impostosDevolucao(array $linha, float $quantidade, int $i, array &$erros): array
    {
        $entrada = is_array($linha['entrada'] ?? null) ? $linha['entrada'] : null;
        $num = fn (array $origem, string $campo) => round((float) ($origem[$campo] ?? 0), 4);

        if ($entrada) {
            $qtdComprada = (float) $entrada['quantidade'];
            if ($quantidade > $qtdComprada + 0.00001) {
                $erros["itens.$i.quantidade"] = 'Não dá para devolver mais do que foi comprado ('.rtrim(rtrim(number_format($qtdComprada, 4, ',', '.'), '0'), ',').').';
            }
            $fator = $qtdComprada > 0 ? min(1, $quantidade / $qtdComprada) : 1;
            $proporcional = fn (string $campo) => round($num($entrada, $campo) * $fator, 2);

            return [
                'cst_csosn' => '900',
                'base_icms' => $proporcional('base_icms'),
                'aliq_icms' => $num($entrada, 'aliq_icms'),
                'valor_icms' => $proporcional('valor_icms'),
                'base_icms_st' => $proporcional('base_icms_st'),
                'aliq_icms_st' => $num($entrada, 'aliq_icms_st'),
                'valor_icms_st' => $proporcional('valor_icms_st'),
                'valor_ipi_devolvido' => $proporcional('valor_ipi'),
                'perc_devolvido' => round($fator * 100, 2),
                'tributos_extras' => ['entrada' => $entrada],
            ];
        }

        $ipi = round((float) ($linha['valor_ipi_devolvido'] ?? 0), 2);

        return [
            'cst_csosn' => '900',
            'base_icms' => round((float) ($linha['base_icms'] ?? 0), 2),
            'aliq_icms' => round((float) ($linha['aliq_icms'] ?? 0), 2),
            'valor_icms' => round((float) ($linha['valor_icms'] ?? 0), 2),
            'base_icms_st' => round((float) ($linha['base_icms_st'] ?? 0), 2),
            'aliq_icms_st' => round((float) ($linha['aliq_icms_st'] ?? 0), 2),
            'valor_icms_st' => round((float) ($linha['valor_icms_st'] ?? 0), 2),
            'valor_ipi_devolvido' => $ipi,
            'perc_devolvido' => $ipi > 0 ? 100 : null,
            'tributos_extras' => null,
        ];
    }

    /** Item que veio do XML da nota de compra (não está no cadastro de produtos). */
    private function copiarItemAvulso(array $l): array
    {
        return [
            'codigo' => mb_substr(trim((string) $l['codigo']), 0, 60),
            'descricao' => mb_substr(trim((string) $l['descricao']), 0, 120),
            'gtin' => preg_match('/^\d{8,14}$/', (string) ($l['gtin'] ?? '')) ? $l['gtin'] : 'SEM GTIN',
            'ncm' => $l['ncm'],
            'cest' => $l['cest'] ?? null,
            'unidade' => mb_substr(strtoupper(trim((string) $l['unidade'])), 0, 6),
            'origem' => (int) ($l['origem'] ?? 0),
            'cst_csosn' => '900',
            'cst_pis' => '49',
            'cst_cofins' => '49',
            'cst_ibs_cbs' => null,
            'cclass_trib' => null,
        ];
    }

    private function copiarPessoa(Destinatario $d): array
    {
        return $d->only([
            'tipo_pessoa', 'cpf_cnpj', 'razao_social', 'nome_fantasia', 'inscricao_estadual', 'ind_ie_dest',
            'email', 'telefone', 'logradouro', 'numero', 'complemento', 'bairro', 'codigo_municipio',
            'municipio', 'uf', 'cep', 'codigo_pais',
        ]);
    }
}
