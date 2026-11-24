<?php

namespace Tests\Unit;

use App\Rules\Cnpj;
use App\Support\Formata;
use PHPUnit\Framework\TestCase;

class CnpjTest extends TestCase
{
    public function test_aceita_cnpj_valido_com_ou_sem_mascara(): void
    {
        $this->assertTrue(Cnpj::valido('11.222.333/0001-81'));
        $this->assertTrue(Cnpj::valido('11222333000181'));
    }

    public function test_recusa_cnpj_invalido(): void
    {
        $this->assertFalse(Cnpj::valido('11222333000182'));
        $this->assertFalse(Cnpj::valido('11111111111111'));
        $this->assertFalse(Cnpj::valido('123'));
    }

    public function test_gera_cnpj_valido(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue(Cnpj::valido(Cnpj::gerar()));
        }
    }

    public function test_formata_cnpj(): void
    {
        $this->assertSame('11.222.333/0001-81', Formata::cnpj('11222333000181'));
    }
}
