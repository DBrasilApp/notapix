<?php

namespace Tests\Feature;

use App\Models\Cfop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CfopTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_carrega_a_tabela_oficial_completa(): void
    {
        $this->assertSame(619, Cfop::count());
        $this->assertTrue(Cfop::find('5405')->ind_nfe);
        $this->assertTrue(Cfop::find('5202')->ind_devolucao);
        $this->assertTrue(Cfop::find('5656')->ind_combustivel);
        $this->assertFalse(Cfop::find('5301')->ind_nfe);
    }

    public function test_pesquisa_vazia_mostra_os_mais_usados(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/empresa/cfop')
            ->assertOk()
            ->assertJsonPath('0.codigo', '5102')
            ->assertJsonCount(count(Cfop::MAIS_USADOS));
    }

    public function test_pesquisa_por_palavras_so_traz_saidas_permitidas_em_nfe(): void
    {
        $itens = $this->actingAs(User::factory()->create())->getJson('/empresa/cfop?q=substituicao tributaria')->json();

        $this->assertNotEmpty($itens);
        $this->assertContains('5405', array_column($itens, 'codigo'));
        foreach ($itens as $item) {
            $this->assertMatchesRegularExpression('/^[5-7]/', $item['codigo']);
        }
    }

    public function test_pesquisa_por_codigo_e_consulta(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/empresa/cfop?q=5.40')->assertJsonFragment(['codigo' => '5405']);
        $this->actingAs($user)->getJson('/empresa/cfop/5405')->assertOk()->assertJsonPath('codigo_formatado', '5.405');
        $this->actingAs($user)->getJson('/empresa/cfop/1102')->assertNotFound();
        $this->actingAs($user)->getJson('/empresa/cfop/5301')->assertNotFound();
    }

    public function test_comando_recarrega_a_tabela(): void
    {
        Cfop::query()->delete();

        $this->artisan('cfop:importar')->assertSuccessful();

        $this->assertSame(619, Cfop::count());
    }
}
