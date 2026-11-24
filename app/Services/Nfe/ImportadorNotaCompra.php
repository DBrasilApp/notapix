<?php

namespace App\Services\Nfe;

use App\Models\Destinatario;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use App\Rules\InscricaoEstadual;
use App\Support\ChaveAcesso;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Lê o XML de uma NF-e de compra (recebida do fornecedor) e cria o rascunho da
 * nota de DEVOLUÇÃO: fornecedor como destinatário, chave referenciada e os itens
 * com os impostos da compra (ICMS, ICMS-ST e IPI), que são devolvidos na proporção
 * da quantidade. O usuário depois remove os itens ou reduz as quantidades.
 */
class ImportadorNotaCompra
{
    public function __construct(private SalvarNota $salvar)
    {
    }

    public function importar(Empresa $empresa, User $usuario, string $xml): Nota
    {
        $dom = new DOMDocument;
        if (trim($xml) === '' || ! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('O arquivo não é um XML válido de NF-e.');
        }

        $inf = $dom->getElementsByTagName('infNFe')->item(0);
        if (! $inf instanceof DOMElement) {
            throw new RuntimeException('O arquivo não é um XML de NF-e (não tem o grupo infNFe).');
        }

        $chave = ChaveAcesso::limpar(preg_replace('/^NFe/', '', $inf->getAttribute('Id')));
        if (! ChaveAcesso::valida($chave)) {
            throw new RuntimeException('Não foi possível ler a chave de acesso da nota.');
        }

        $ide = $this->filho($inf, 'ide');
        $emit = $this->filho($inf, 'emit');
        $dest = $this->filho($inf, 'dest');

        if ($this->texto($ide, 'tpNF') !== '1') {
            throw new RuntimeException('Esta nota não é uma nota de saída do fornecedor (venda para você).');
        }
        if (! in_array($this->texto($ide, 'mod'), ['55'], true)) {
            throw new RuntimeException('Só é possível importar NF-e (modelo 55).');
        }
        if ($this->texto($dest, 'CNPJ') !== $empresa->cnpj) {
            throw new RuntimeException('Esta nota não foi emitida para a sua empresa (o destinatário é outro CNPJ).');
        }
        if ($this->texto($emit, 'CNPJ') === null) {
            throw new RuntimeException('O emitente desta nota não tem CNPJ (produtor rural pessoa física ainda não é suportado).');
        }

        $fornecedor = $this->fornecedor($empresa, $emit);
        $interestadual = strtoupper((string) $fornecedor->uf) !== strtoupper((string) $empresa->uf);

        $itens = [];
        foreach ($inf->getElementsByTagName('det') as $det) {
            $itens[] = $this->item($det, $interestadual);
        }

        if (! $itens) {
            throw new RuntimeException('A nota não tem itens.');
        }

        return $this->salvar->salvar($empresa, $usuario, [
            'operacao' => 'devolucao',
            'destinatario_id' => $fornecedor->id,
            'natureza_operacao' => Nota::OPERACOES['devolucao']['natureza'],
            'modalidade_frete' => 9,
            'forma_pagamento' => '90',
            'ind_pagamento' => 0,
            'referencias' => [['tipo' => 'nfe', 'chave' => $chave]],
            'itens' => $itens,
            'informacoes_complementares' => null,
        ]);
    }

    /** Acha o fornecedor em Pessoas pelo CNPJ ou cadastra com os dados do XML. */
    private function fornecedor(Empresa $empresa, DOMElement $emit): Destinatario
    {
        $cnpj = $this->texto($emit, 'CNPJ');
        $existente = $empresa->destinatarios()->where('cpf_cnpj', $cnpj)->first();
        if ($existente) {
            return $existente;
        }

        $end = $this->filho($emit, 'enderEmit');
        $uf = strtoupper((string) $this->texto($end, 'UF'));
        $ie = $this->texto($emit, 'IE');
        $temIe = $ie && preg_match('/\d/', $ie);

        return $empresa->destinatarios()->create([
            'tipo_pessoa' => Destinatario::JURIDICA,
            'cpf_cnpj' => $cnpj,
            'razao_social' => mb_substr((string) $this->texto($emit, 'xNome'), 0, 60),
            'nome_fantasia' => ($f = $this->texto($emit, 'xFant')) ? mb_substr($f, 0, 60) : null,
            'inscricao_estadual' => $temIe ? InscricaoEstadual::normalizar($ie, $uf) : null,
            'ind_ie_dest' => $temIe ? Destinatario::IE_CONTRIBUINTE : Destinatario::IE_NAO_CONTRIBUINTE,
            'telefone' => ($fone = preg_replace('/\D/', '', (string) $this->texto($end, 'fone'))) !== '' ? mb_substr($fone, 0, 11) : null,
            'logradouro' => mb_substr((string) $this->texto($end, 'xLgr'), 0, 60),
            'numero' => mb_substr((string) ($this->texto($end, 'nro') ?? 'S/N'), 0, 60),
            'complemento' => ($c = $this->texto($end, 'xCpl')) ? mb_substr($c, 0, 60) : null,
            'bairro' => mb_substr((string) $this->texto($end, 'xBairro'), 0, 60),
            'codigo_municipio' => $this->texto($end, 'cMun'),
            'municipio' => mb_substr((string) $this->texto($end, 'xMun'), 0, 60),
            'uf' => $uf,
            'cep' => preg_replace('/\D/', '', (string) $this->texto($end, 'CEP')) ?: null,
            'codigo_pais' => '1058',
        ]);
    }

    private function item(DOMElement $det, bool $interestadual): array
    {
        $prod = $this->filho($det, 'prod');
        $imposto = $this->filho($det, 'imposto');
        $icms = $imposto?->getElementsByTagName('ICMS')->item(0);
        $icms = $icms instanceof DOMElement ? $this->primeiroFilho($icms) : null;
        $ipi = $imposto?->getElementsByTagName('IPITrib')->item(0);

        $num = fn (?DOMElement $no, string $tag) => (float) ($no ? $this->texto($no, $tag) : 0);
        $quantidade = $num($prod, 'qCom');
        $vIcmsSt = $num($icms, 'vICMSST');
        $cst = (string) ($icms ? ($this->texto($icms, 'CST') ?? $this->texto($icms, 'CSOSN')) : '');
        $cfopCompra = (string) $this->texto($prod, 'CFOP');

        // Compra com substituição tributária (ST) => devolução 5.411/6.411; senão 5.202/6.202
        $comSt = $vIcmsSt > 0 || in_array(substr($cfopCompra, 1), ['401', '403', '405'], true)
            || in_array($cst, ['10', '30', '60', '70', '201', '202', '203', '500'], true);
        $cfop = ($interestadual ? '6' : '5').($comSt ? '411' : '202');

        $gtin = (string) $this->texto($prod, 'cEAN');

        return [
            'produto_id' => null,
            'codigo' => mb_substr((string) $this->texto($prod, 'cProd'), 0, 60),
            'descricao' => mb_substr((string) $this->texto($prod, 'xProd'), 0, 120),
            'gtin' => preg_match('/^\d{8,14}$/', $gtin) ? $gtin : 'SEM GTIN',
            'ncm' => (string) $this->texto($prod, 'NCM'),
            'cest' => $this->texto($prod, 'CEST'),
            'unidade' => mb_substr((string) $this->texto($prod, 'uCom'), 0, 6),
            'origem' => (int) ($icms ? $this->texto($icms, 'orig') : 0),
            'quantidade' => $quantidade,
            'valor_unitario' => $num($prod, 'vUnCom'),
            'desconto' => $num($prod, 'vDesc'),
            'cfop' => $cfop,
            'entrada' => [
                'n_item' => (int) $det->getAttribute('nItem') ?: null,
                'quantidade' => $quantidade,
                'valor_unitario' => $num($prod, 'vUnCom'),
                'valor_desconto' => $num($prod, 'vDesc'),
                'cfop' => $cfopCompra,
                'cst' => $cst,
                'base_icms' => $num($icms, 'vBC'),
                'aliq_icms' => $num($icms, 'pICMS'),
                'valor_icms' => $num($icms, 'vICMS'),
                'base_icms_st' => $num($icms, 'vBCST'),
                'aliq_icms_st' => $num($icms, 'pICMSST'),
                'valor_icms_st' => $vIcmsSt,
                'valor_ipi' => $num($ipi instanceof DOMElement ? $ipi : null, 'vIPI'),
            ],
        ];
    }

    private function filho(?DOMElement $pai, string $tag): ?DOMElement
    {
        $no = $pai?->getElementsByTagName($tag)->item(0);

        return $no instanceof DOMElement ? $no : null;
    }

    private function primeiroFilho(DOMElement $pai): ?DOMElement
    {
        foreach ($pai->childNodes as $no) {
            if ($no instanceof DOMElement) {
                return $no;
            }
        }

        return null;
    }

    private function texto(?DOMElement $pai, string $tag): ?string
    {
        $no = $pai?->getElementsByTagName($tag)->item(0);
        $valor = $no ? trim($no->nodeValue) : '';

        return $valor === '' ? null : $valor;
    }
}
