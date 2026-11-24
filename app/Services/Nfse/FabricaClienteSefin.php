<?php

namespace App\Services\Nfse;

use App\Models\Empresa;

/** Cria o cliente da SEFIN Nacional de cada empresa. Nos testes é trocada por uma fábrica falsa. */
class FabricaClienteSefin
{
    public function para(Empresa $empresa): ClienteSefin
    {
        return new ClienteSefinHttp($empresa);
    }
}
