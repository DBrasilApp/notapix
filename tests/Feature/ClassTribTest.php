<?php

namespace Tests\Feature;

use App\Models\ClassTrib;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassTribTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabela_oficial_e_carregada_pela_migration(): void
    {
        $this->assertGreaterThan(150, ClassTrib::count());
        $integral = ClassTrib::find('000001');
        $this->assertSame('000', $integral->cst);
        $this->assertTrue($integral->ind_nfe);
    }

    public function test_pesquisa_por_descricao_codigo_e_sugestoes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/empresa/classtrib?q=hortícolas')->assertOk()
            ->assertJsonFragment(['codigo' => '200014']);
        $this->actingAs($user)->getJson('/empresa/classtrib?q=0000')->assertOk()
            ->assertJsonFragment(['codigo' => '000001']);
        $this->actingAs($user)->getJson('/empresa/classtrib?q=')->assertOk()
            ->assertJsonPath('0.codigo', '000001');

        $this->actingAs($user)->getJson('/empresa/classtrib/000001')->assertOk()->assertJsonPath('cst', '000');
        $this->actingAs($user)->getJson('/empresa/classtrib/999999')->assertNotFound();
    }

    public function test_classificacao_fora_da_nfe_nao_aparece(): void
    {
        $user = User::factory()->create();
        $foraNfe = ClassTrib::where('ind_nfe', false)->value('codigo');

        $this->actingAs($user)->getJson("/empresa/classtrib/{$foraNfe}")->assertNotFound();
    }

    public function test_comando_recarrega_do_arquivo(): void
    {
        ClassTrib::query()->delete();

        $this->artisan('classtrib:importar')->assertSuccessful();
        $this->assertGreaterThan(150, ClassTrib::count());
    }

    public function test_aceita_resposta_em_camel_case(): void
    {
        $oficial = \App\Services\ImportadorClassTrib::lerArquivo();
        $camel = json_decode(preg_replace_callback('/"([A-Z])([A-Za-z]*)":/', fn ($m) => '"'.strtolower($m[1]).$m[2].'":', json_encode($oficial)), true);

        $total = app(\App\Services\ImportadorClassTrib::class)->importar($camel);

        $this->assertGreaterThan(150, $total);
        $this->assertSame('Tributação integral', ClassTrib::find('000001')->nome_cst);
    }
}
