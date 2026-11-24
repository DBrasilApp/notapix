<?php

namespace Tests\Unit;

use App\Rules\Cpf;
use App\Rules\Gtin;
use App\Support\Formata;
use PHPUnit\Framework\TestCase;

class DocumentosTest extends TestCase
{
    public function test_cpf(): void
    {
        $this->assertTrue(Cpf::valido('529.982.247-25'));
        $this->assertFalse(Cpf::valido('529.982.247-26'));
        $this->assertFalse(Cpf::valido('111.111.111-11'));
        $this->assertSame('529.982.247-25', Formata::cpf('52998224725'));
    }

    public function test_gtin(): void
    {
        $this->assertTrue(Gtin::valido('7891000315507'));   // EAN-13
        $this->assertTrue(Gtin::valido('96385074'));        // EAN-8
        $this->assertFalse(Gtin::valido('7891000315508'));
        $this->assertFalse(Gtin::valido('123'));
    }

    public function test_formata_ncm(): void
    {
        $this->assertSame('6109.10.00', Formata::ncm('61091000'));
    }
}
