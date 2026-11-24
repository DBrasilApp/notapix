<?php

namespace App\Services\Nfe;

use DOMDocument;

/** Leitura da resposta da SEFAZ (autorização ou consulta por chave). */
final class ResultadoSefaz
{
    public const AUTORIZADA = 'autorizada';
    public const DENEGADA = 'denegada';
    public const REJEITADA = 'rejeitada';
    public const PROCESSANDO = 'processando';
    public const DUPLICIDADE = 'duplicidade';
    public const NAO_ENCONTRADA = 'nao_encontrada';
    public const CANCELADA = 'cancelada';

    private function __construct(
        public readonly string $situacao,
        public readonly ?int $codigo,
        public readonly string $motivo,
        public readonly ?string $protocolo = null,
        public readonly ?string $recibo = null,
        public readonly ?string $dataRecebimento = null,
    ) {
    }

    public static function interpretar(string $xmlResposta): self
    {
        $dom = new DOMDocument;
        if (trim($xmlResposta) === '' || ! @$dom->loadXML($xmlResposta)) {
            return new self(self::PROCESSANDO, null, 'Resposta da SEFAZ ilegível ou vazia.');
        }

        $texto = fn (\DOMNode $no, string $tag) => $no instanceof \DOMElement && $no->getElementsByTagName($tag)->length
            ? trim($no->getElementsByTagName($tag)->item(0)->nodeValue)
            : null;

        // Situação da nota, quando a resposta trouxer o protocolo
        $infProt = $dom->getElementsByTagName('infProt')->item(0);
        if ($infProt) {
            $codigo = (int) $texto($infProt, 'cStat');

            return new self(
                self::situacaoDaNota($codigo),
                $codigo,
                (string) $texto($infProt, 'xMotivo'),
                $texto($infProt, 'nProt'),
                null,
                $texto($infProt, 'dhRecbto'),
            );
        }

        // Sem protocolo: resposta do lote (ou da consulta)
        $raiz = $dom->documentElement;
        $retorno = null;
        foreach (['retEnviNFe', 'retConsSitNFe', 'retConsReciNFe', 'retConsStatServ'] as $tag) {
            if ($dom->getElementsByTagName($tag)->length) {
                $retorno = $dom->getElementsByTagName($tag)->item(0);
                break;
            }
        }
        $retorno ??= $raiz;

        $codigo = (int) $texto($retorno, 'cStat');
        $motivo = (string) $texto($retorno, 'xMotivo');

        $situacao = match (true) {
            in_array($codigo, [103, 105], true) => self::PROCESSANDO,
            $codigo === 217 => self::NAO_ENCONTRADA,
            in_array($codigo, [101, 151, 155], true) => self::CANCELADA,
            $codigo === 0 => self::PROCESSANDO,
            default => self::REJEITADA,
        };

        return new self($situacao, $codigo ?: null, $motivo ?: 'Sem motivo informado pela SEFAZ.', null, $texto($retorno, 'nRec'));
    }

    private static function situacaoDaNota(int $codigo): string
    {
        return match (true) {
            in_array($codigo, [100, 150], true) => self::AUTORIZADA,
            in_array($codigo, [110, 301, 302, 303], true) => self::DENEGADA,
            $codigo === 204 => self::DUPLICIDADE,
            default => self::REJEITADA,
        };
    }
}
