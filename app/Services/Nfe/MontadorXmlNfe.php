<?php

namespace App\Services\Nfe;

use App\Models\Empresa;
use App\Models\Nota;
use App\Models\NotaItem;
use NFePHP\NFe\Make;

/**
 * Monta o XML da NF-e (modelo 55) ou da NFC-e (modelo 65), leiaute 4.00 / PL_010, a partir de uma nota gravada.
 * O XML sai sem assinatura; quem assina é o ClienteSefaz.
 */
class MontadorXmlNfe
{
    /** Código IBGE das UFs (cUF). */
    public const CODIGOS_UF = [
        'RO' => 11, 'AC' => 12, 'AM' => 13, 'RR' => 14, 'PA' => 15, 'AP' => 16, 'TO' => 17,
        'MA' => 21, 'PI' => 22, 'CE' => 23, 'RN' => 24, 'PB' => 25, 'PE' => 26, 'AL' => 27, 'SE' => 28, 'BA' => 29,
        'MG' => 31, 'ES' => 32, 'RJ' => 33, 'SP' => 35, 'PR' => 41, 'SC' => 42, 'RS' => 43,
        'MS' => 50, 'MT' => 51, 'GO' => 52, 'DF' => 53,
    ];

    /** Formas de pagamento que exigem o grupo de cartão/integração. */
    private const PAGAMENTOS_COM_CARTAO = ['03', '04', '17', '18'];

    /**
     * @return array{xml: string, chave: string}
     * @throws ErroMontagemNfe com a lista de problemas encontrados pela NFePHP
     */
    public function montar(Nota $nota): array
    {
        $nota->loadMissing('itens', 'empresa');
        $empresa = $nota->empresa;
        $dest = $nota->destinatario_dados;

        $mk = new Make(config('nfeexpress.nfe.schemes'));
        $mk->setOnlyAscii(false);
        $mk->setCheckGtin(true);

        $mk->taginfNFe((object) ['Id' => null, 'versao' => '4.00']);

        $mk->tagide((object) [
            'cUF' => self::CODIGOS_UF[$empresa->uf],
            // Reaproveita o código numérico de uma tentativa anterior: a chave continua a mesma
            'cNF' => $nota->chave ? substr($nota->chave, 35, 8) : null,
            'natOp' => $nota->natureza_operacao,
            'mod' => (int) ($nota->modelo ?: 55),
            'serie' => $nota->serie,
            'nNF' => $nota->numero,
            'dhEmi' => null,
            'dhSaiEnt' => null,
            'tpNF' => $nota->tipo_operacao,
            'idDest' => $nota->id_dest,
            'cMunFG' => $empresa->codigo_municipio,
            'tpImp' => $nota->ehNfce() ? 4 : 1, // 4 = DANFE NFC-e (cupom)
            'tpEmis' => 1,
            'tpAmb' => $nota->ambiente,
            'finNFe' => $nota->finalidade,
            'indFinal' => $nota->ind_final,
            'indPres' => $nota->ind_pres,
            'indIntermed' => 0,
            'procEmi' => 0,
            'verProc' => config('app.name').' 1.0',
        ]);

        $this->referencias($mk, $nota);
        $this->emitente($mk, $empresa);
        if (! $nota->ehNfce()) {
            $this->destinatario($mk, $dest);
        } elseif (! empty($dest['cpf_cnpj'])) {
            // NFC-e: consumidor identificado só pelo CPF/CNPJ (sem endereço)
            $cpf = strlen($dest['cpf_cnpj']) === 11;
            $mk->tagdest((object) [
                'xNome' => ! empty($dest['razao_social']) ? mb_substr($dest['razao_social'], 0, 60) : null,
                'CPF' => $cpf ? $dest['cpf_cnpj'] : null,
                'CNPJ' => $cpf ? null : $dest['cpf_cnpj'],
                'indIEDest' => 9,
            ]);
        }

        foreach ($nota->itens as $item) {
            $this->item($mk, $item);
        }

        $mk->tagtransp((object) ['modFrete' => $nota->modalidade_frete]);

        if (! empty($nota->pagamentos)) {
            // NFC-e: uma ou mais formas de pagamento, com troco
            $mk->tagpag((object) ['vTroco' => (float) $nota->troco > 0 ? (float) $nota->troco : null]);
            foreach ($nota->pagamentos as $pagamento) {
                $mk->tagdetPag((object) [
                    'indPag' => 0,
                    'tPag' => $pagamento['forma'],
                    'xPag' => $pagamento['forma'] === '99' ? ($pagamento['descricao'] ?? 'Outros') : null,
                    'vPag' => (float) $pagamento['valor'],
                    'tpIntegra' => in_array($pagamento['forma'], self::PAGAMENTOS_COM_CARTAO, true) ? 2 : null,
                ]);
            }
        } else {
            $mk->tagpag((object) ['vTroco' => null]);
            $semPagamento = $nota->forma_pagamento === '90';
            $mk->tagdetPag((object) [
                'indPag' => $semPagamento ? null : $nota->ind_pagamento,
                'tPag' => $nota->forma_pagamento,
                'xPag' => $nota->forma_pagamento === '99' ? $nota->descricao_pagamento : null,
                'vPag' => $semPagamento ? 0 : (float) $nota->valor_total,
                'tpIntegra' => in_array($nota->forma_pagamento, self::PAGAMENTOS_COM_CARTAO, true) ? 2 : null,
            ]);
        }

        $complementar = trim(implode(' ', array_filter([
            $this->textoRegime($empresa),
            $this->textoReferencias($nota),
            $nota->informacoes_complementares ? preg_replace('/\s+/', ' ', $nota->informacoes_complementares) : null,
        ])));
        if ($complementar !== '') {
            $mk->taginfAdic((object) ['infAdFisco' => null, 'infCpl' => mb_substr($complementar, 0, 5000)]);
        }

        $resp = config('nfeexpress.nfe.resp_tec');
        if (! empty($resp['cnpj'])) {
            $mk->taginfRespTec((object) [
                'CNPJ' => preg_replace('/\D/', '', $resp['cnpj']),
                'xContato' => $resp['contato'],
                'email' => $resp['email'],
                'fone' => preg_replace('/\D/', '', (string) $resp['fone']),
            ]);
        }

        $xml = $mk->render();
        $erros = $mk->getErrors();

        if ($erros) {
            throw new ErroMontagemNfe($erros);
        }

        return ['xml' => $xml, 'chave' => $mk->getChave()];
    }

    private function emitente(Make $mk, Empresa $empresa): void
    {
        $mk->tagemit((object) [
            'xNome' => $empresa->razao_social,
            'xFant' => $empresa->nome_fantasia ?: null,
            'IE' => $empresa->inscricao_estadual,
            'CRT' => $empresa->crt,
            'CNPJ' => $empresa->cnpj,
        ]);

        $mk->tagenderemit((object) [
            'xLgr' => $empresa->logradouro,
            'nro' => $empresa->numero,
            'xCpl' => $empresa->complemento ?: null,
            'xBairro' => $empresa->bairro,
            'cMun' => $empresa->codigo_municipio,
            'xMun' => $empresa->municipio,
            'UF' => $empresa->uf,
            'CEP' => $empresa->cep,
            'cPais' => 1058,
            'xPais' => 'Brasil',
            'fone' => $empresa->telefone ?: null,
        ]);
    }

    private function destinatario(Make $mk, array $d): void
    {
        $fisica = ($d['tipo_pessoa'] ?? 'J') === 'F';
        $indIe = (int) ($d['ind_ie_dest'] ?? 9);

        $mk->tagdest((object) [
            'xNome' => $d['razao_social'],
            'CNPJ' => $fisica ? null : $d['cpf_cnpj'],
            'CPF' => $fisica ? $d['cpf_cnpj'] : null,
            'indIEDest' => $indIe,
            'IE' => $indIe === 1 ? $d['inscricao_estadual'] : null,
            'email' => ! empty($d['email']) ? mb_substr($d['email'], 0, 60) : null,
        ]);

        $mk->tagenderdest((object) [
            'xLgr' => $d['logradouro'],
            'nro' => $d['numero'],
            'xCpl' => ! empty($d['complemento']) ? $d['complemento'] : null,
            'xBairro' => $d['bairro'],
            'cMun' => $d['codigo_municipio'],
            'xMun' => $d['municipio'],
            'UF' => $d['uf'],
            'CEP' => $d['cep'] ?? null,
            'cPais' => 1058,
            'xPais' => 'Brasil',
            'fone' => ! empty($d['telefone']) ? $d['telefone'] : null,
        ]);
    }

    private function item(Make $mk, NotaItem $item): void
    {
        $n = $item->numero_item;
        $desconto = (float) $item->valor_desconto;

        $mk->tagprod((object) [
            'item' => $n,
            'cProd' => $item->codigo,
            'cEAN' => $item->gtin ?: 'SEM GTIN',
            'xProd' => $item->descricao,
            'NCM' => $item->ncm,
            'CEST' => $item->cest ?: null,
            'CFOP' => $item->cfop,
            'uCom' => $item->unidade,
            'qCom' => (float) $item->quantidade,
            'vUnCom' => (float) $item->valor_unitario,
            'vProd' => (float) $item->valor_total,
            'cEANTrib' => $item->gtin ?: 'SEM GTIN',
            'uTrib' => $item->unidade,
            'qTrib' => (float) $item->quantidade,
            'vUnTrib' => (float) $item->valor_unitario,
            'vDesc' => $desconto > 0 ? $desconto : null,
            'indTot' => 1,
        ]);

        $mk->tagimposto((object) ['item' => $n]);

        $icms = ['item' => $n, 'orig' => (string) $item->origem, 'CSOSN' => $item->cst_csosn];

        // CSOSN 900 (devolução de compra): devolve destacado o ICMS e o ICMS-ST da nota de compra
        if ($item->cst_csosn === '900') {
            if ((float) $item->valor_icms > 0) {
                $icms += [
                    'modBC' => 3,
                    'vBC' => (float) $item->base_icms,
                    'pICMS' => (float) $item->aliq_icms,
                    'vICMS' => (float) $item->valor_icms,
                ];
            }
            if ((float) $item->valor_icms_st > 0) {
                $icms += [
                    'modBCST' => 4,
                    'vBCST' => (float) $item->base_icms_st,
                    'pICMSST' => (float) $item->aliq_icms_st,
                    'vICMSST' => (float) $item->valor_icms_st,
                ];
            }
        }
        $mk->tagICMSSN((object) $icms);

        foreach (['PIS' => $item->cst_pis, 'COFINS' => $item->cst_cofins] as $tributo => $cst) {
            $dados = ['item' => $n, 'CST' => $cst];
            // CST 49/99 (grupo "Outros") exige base, alíquota e valor, que no Simples são zero
            if (in_array($cst, ['49', '99'], true)) {
                $dados += ['vBC' => 0, "p{$tributo}" => 0, "v{$tributo}" => 0];
            }
            $tributo === 'PIS' ? $mk->tagPIS((object) $dados) : $mk->tagCOFINS((object) $dados);
        }

        // IPI que o fornecedor cobrou na compra volta como "IPI devolvido" (soma no total da nota)
        if ((float) $item->valor_ipi_devolvido > 0) {
            $mk->tagimpostoDevol((object) [
                'item' => $n,
                'pDevol' => (float) ($item->perc_devolvido ?: 100),
                'vIPIDevol' => (float) $item->valor_ipi_devolvido,
            ]);
        }

        // IBS/CBS: para Simples Nacional e MEI a exigência começa em 2027 (NT 2025.002-RTC).
        // Enquanto isso o grupo não é enviado.
    }

    /** Documentos referenciados (NF-e pela chave; nota modelo 1/1A pelos dados). */
    private function referencias(Make $mk, Nota $nota): void
    {
        foreach ((array) $nota->referencias as $ref) {
            if (($ref['tipo'] ?? 'nfe') === 'nf') {
                [$mes, $ano] = array_pad(explode('/', (string) $ref['mes_ano']), 2, '');
                $mk->tagrefNF((object) [
                    'cUF' => self::CODIGOS_UF[$ref['uf']] ?? null,
                    'AAMM' => substr($ano, -2).str_pad($mes, 2, '0', STR_PAD_LEFT),
                    'CNPJ' => $ref['cnpj'],
                    'mod' => $ref['modelo'],
                    'serie' => (int) $ref['serie'],
                    'nNF' => (int) $ref['numero'],
                ]);
            } else {
                $mk->tagrefNFe((object) ['refNFe' => $ref['chave']]);
            }
        }
    }

    private function textoReferencias(Nota $nota): ?string
    {
        if (! $nota->ehDevolucao() || empty($nota->referencias)) {
            return null;
        }

        $textos = array_map(function (array $ref) {
            if (($ref['tipo'] ?? 'nfe') === 'nf') {
                return "NF n. {$ref['numero']} serie {$ref['serie']} de {$ref['mes_ano']}";
            }
            $d = \App\Support\ChaveAcesso::decompor($ref['chave']);

            return $d ? "NF-e n. {$d['numero']} serie {$d['serie']} de {$d['mes']}/{$d['ano']} (chave {$ref['chave']})" : "NF-e chave {$ref['chave']}";
        }, $nota->referencias);

        return 'Devolucao referente a '.implode('; ', $textos).'.';
    }

    private function textoRegime(Empresa $empresa): ?string
    {
        return in_array($empresa->crt, [Empresa::CRT_SIMPLES, Empresa::CRT_MEI], true)
            ? config('nfeexpress.nfe.texto_simples')
            : null;
    }
}
