<?php

namespace App\Services\Nfe;

use App\Models\Empresa;

/** Cria o cliente da SEFAZ de cada empresa. Nos testes é substituída por uma fábrica falsa. */
class FabricaClienteSefaz
{
    public function para(Empresa $empresa, int $modelo = 55): ClienteSefaz
    {
        return new ClienteSefazNfephp($empresa, $modelo);
    }
}
