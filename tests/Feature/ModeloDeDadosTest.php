<?php

namespace Tests\Feature;

use App\Models\Certificado;
use App\Models\CreditoMovimento;
use App\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModeloDeDadosTest extends TestCase
{
    use RefreshDatabase;

    public function test_saldo_e_a_soma_dos_movimentos(): void
    {
        $empresa = Empresa::factory()->create();

        $mov = fn (string $tipo, int $qtd) => CreditoMovimento::create([
            'carteira_type' => 'empresa',
            'carteira_id' => $empresa->id,
            'tipo' => $tipo,
            'quantidade' => $qtd,
        ]);

        $mov(CreditoMovimento::CORTESIA, 5);
        $mov(CreditoMovimento::RESERVA, -1);
        $mov(CreditoMovimento::RESERVA, -1);
        $mov(CreditoMovimento::ESTORNO, 1);

        $this->assertSame(4, $empresa->saldoCreditos());
    }

    public function test_movimento_de_credito_nao_pode_ser_alterado(): void
    {
        $mov = CreditoMovimento::create([
            'carteira_type' => 'empresa',
            'carteira_id' => Empresa::factory()->create()->id,
            'tipo' => CreditoMovimento::CORTESIA,
            'quantidade' => 5,
        ]);

        $this->expectException(\LogicException::class);
        $mov->update(['quantidade' => 500]);
    }

    public function test_certificado_e_senha_ficam_criptografados_no_banco(): void
    {
        $empresa = Empresa::factory()->create();

        $cert = Certificado::create([
            'empresa_id' => $empresa->id,
            'arquivo_pfx' => 'conteudo-binario-do-pfx',
            'senha' => 'minha-senha-secreta',
        ]);

        $bruto = DB::table('certificados')->where('id', $cert->id)->first();

        $this->assertNotSame('minha-senha-secreta', $bruto->senha);
        $this->assertStringNotContainsString('conteudo-binario', $bruto->arquivo_pfx);
        $this->assertSame('minha-senha-secreta', $cert->fresh()->senha);
        $this->assertArrayNotHasKey('senha', $cert->toArray());
    }
}
