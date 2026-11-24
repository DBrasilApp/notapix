<?php

namespace App\Services\Nfse;

/** Comunicação com a SEFIN Nacional (emissão de NFS-e). Nos testes é trocado por um falso. */
interface ClienteSefin
{
    /** Assina o XML (DPS ou pedido de evento) com o certificado da empresa. */
    public function assinar(string $xml, string $tag): string;

    /** POST /nfse: envia a DPS assinada e recebe a NFS-e (síncrono). */
    public function emitir(string $dpsAssinada): RespostaSefin;

    /** GET /dps/{id}: devolve a chave da NFS-e gerada por esta DPS, ou null se a SEFIN não a tem. */
    public function chavePorDps(string $idDps): ?string;

    /** GET /nfse/{chave}: o XML da NFS-e. */
    public function consultarNfse(string $chave): RespostaSefin;

    /** POST /nfse/{chave}/eventos: cancelamento e outros eventos. */
    public function registrarEvento(string $chave, string $pedidoAssinado): RespostaSefin;

    /** Último pedido enviado (para o log). */
    public function ultimaRequisicao(): ?string;
}
