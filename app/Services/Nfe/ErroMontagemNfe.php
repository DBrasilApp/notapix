<?php

namespace App\Services\Nfe;

use RuntimeException;

/** Problemas encontrados ao montar ou validar o XML, antes de chegar na SEFAZ. */
class ErroMontagemNfe extends RuntimeException
{
    public function __construct(public readonly array $erros)
    {
        parent::__construct('Erro ao montar a NF-e: '.implode(' | ', $erros));
    }
}
