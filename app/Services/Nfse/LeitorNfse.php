<?php

namespace App\Services\Nfse;

use DOMDocument;
use DOMXPath;

/** Lê os dados que interessam do XML da NFS-e devolvido pela SEFIN. */
final class LeitorNfse
{
    private DOMXPath $xp;

    public function __construct(string $xml)
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET);
        $this->xp = new DOMXPath($dom);
        $this->xp->registerNamespace('n', MontadorDps::NS);
    }

    public function texto(string $caminho): ?string
    {
        $no = $this->xp->query($caminho)->item(0);
        $valor = $no ? trim($no->textContent) : '';

        return $valor === '' ? null : $valor;
    }

    public function chave(): ?string
    {
        $id = $this->xp->query('//n:infNFSe/@Id')->item(0)?->nodeValue;

        return $id && preg_match('/(\d{50})$/', $id, $m) ? $m[1] : null;
    }

    public function numero(): ?string
    {
        return $this->texto('//n:infNFSe/n:nNFSe');
    }

    public function dataProcessamento(): ?string
    {
        return $this->texto('//n:infNFSe/n:dhProc');
    }

    /** Valores calculados pela SEFIN (vBC, pAliqAplic, vISSQN, vTotalRet, vLiq). */
    public function valores(): array
    {
        $r = [];
        foreach (['vBC', 'pAliqAplic', 'vISSQN', 'vTotalRet', 'vLiq'] as $campo) {
            $r[$campo] = $this->texto("//n:infNFSe/n:valores/n:{$campo}");
        }

        return $r;
    }
}
