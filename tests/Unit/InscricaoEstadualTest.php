<?php

namespace Tests\Unit;

use App\Rules\InscricaoEstadual;
use PHPUnit\Framework\TestCase;

class InscricaoEstadualTest extends TestCase
{
    public function test_valida_digito_verificador_do_es(): void
    {
        $this->assertTrue(InscricaoEstadual::validaEs('082560668'));
        $this->assertFalse(InscricaoEstadual::validaEs('082560669'));
        $this->assertFalse(InscricaoEstadual::validaEs('08256066'));
    }
}
