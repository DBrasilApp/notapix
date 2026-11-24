<?php

namespace Tests\Feature;

use App\Models\Ncm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TabelaNcmFalsa;
use Tests\TestCase;

class NcmTest extends TestCase
{
    use RefreshDatabase, TabelaNcmFalsa;

    public function test_comando_baixa_e_importa_so_codigos_de_8_digitos(): void
    {
        Http::fake(['portalunico.siscomex.gov.br/*' => Http::response($this->jsonNcm())]);

        $this->artisan('ncm:importar')->assertSuccessful();

        $this->assertNull(Ncm::find('61'));
        $this->assertNull(Ncm::find('6109'));

        $ncm = Ncm::find('61091000');
        $this->assertSame('De algodão', $ncm->descricao);
        $this->assertSame('Camisetas ("T-shirts") e camisetas interiores, de malha › De algodão', $ncm->descricao_completa);

        $this->assertSame('Café, mesmo torrado ou descafeinado › Café torrado › Não descafeinado', Ncm::find('09012100')->descricao_completa);
    }

    public function test_importar_de_arquivo_local(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'ncm');
        file_put_contents($arquivo, "\xEF\xBB\xBF".$this->jsonNcm()); // com BOM, como às vezes vem

        $this->artisan('ncm:importar', ['--arquivo' => $arquivo])->assertSuccessful();
        unlink($arquivo);

        $this->assertTrue(Ncm::whereKey('61091000')->exists());
    }

    public function test_arquivo_invalido_nao_apaga_a_tabela_atual(): void
    {
        $this->importarNcmFalsa();
        Http::fake(['*' => Http::response('{"Nomenclaturas": []}')]);

        $this->artisan('ncm:importar')->assertFailed();

        $this->assertTrue(Ncm::whereKey('61091000')->exists());
    }

    public function test_busca_por_palavras_sem_acento_e_por_codigo(): void
    {
        $this->importarNcmFalsa();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/empresa/ncm?q=camiseta algodao')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.codigo', '61091000')
            ->assertJsonPath('0.codigo_formatado', '6109.10.00');

        $this->actingAs($user)->getJson('/empresa/ncm?q=6109')->assertJsonCount(2);
        $this->actingAs($user)->getJson('/empresa/ncm?q=cafe torrado')->assertJsonCount(1);
    }

    public function test_busca_nao_traz_codigo_fora_de_vigencia(): void
    {
        $this->importarNcmFalsa();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/empresa/ncm?q=09019999')->assertJsonCount(0);
        $this->actingAs($user)->getJson('/empresa/ncm/09019999')->assertNotFound();
    }

    public function test_consulta_por_codigo(): void
    {
        $this->importarNcmFalsa();

        $this->actingAs(User::factory()->create())->getJson('/empresa/ncm/61091000')
            ->assertOk()
            ->assertJsonPath('descricao', 'De algodão');
    }
}
