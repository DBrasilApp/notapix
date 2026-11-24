<?php

namespace App\Services\Nfe;

/**
 * Comunicação com a SEFAZ de uma empresa (já com o certificado dela).
 * A implementação real usa a NFePHP; nos testes é trocada por uma falsa.
 */
interface ClienteSefaz
{
    /** Assina o XML e valida contra o schema oficial. Lança exceção se o XML for inválido. */
    public function assinar(string $xml): string;

    /** Envia uma nota (modo síncrono) e devolve o XML de resposta da SEFAZ. */
    public function autorizar(string $xmlAssinado, string $idLote): string;

    /** Consulta a situação de uma nota pela chave e devolve o XML de resposta. */
    public function consultarChave(string $chave): string;

    /** Evento de cancelamento (110111). Devolve o XML de resposta (retEnvEvento). */
    public function cancelar(string $chave, string $justificativa, string $protocolo): string;

    /** Carta de correção (110110), sequência 1 a 20. Devolve o XML de resposta (retEnvEvento). */
    public function cartaCorrecao(string $chave, string $texto, int $sequencia): string;

    /** Consulta o cadastro de contribuintes (CNPJ ou IE) na UF e devolve o XML de resposta. */
    public function consultarCadastro(string $uf, string $cnpj = '', string $ie = ''): string;

    /** Último XML enviado (para o log). */
    public function ultimaRequisicao(): string;
}
