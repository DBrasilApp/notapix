<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcessoPorPerfilTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_e_levado_ao_login(): void
    {
        foreach (['/painel', '/admin', '/escritorio', '/empresa'] as $url) {
            $this->get($url)->assertRedirect('/entrar');
        }
    }

    public function test_cada_perfil_so_acessa_sua_area(): void
    {
        $matriz = [
            '/admin' => ['admin' => 200, 'escritorio' => 403, 'empresa' => 403],
            '/escritorio' => ['admin' => 403, 'escritorio' => 200, 'empresa' => 403],
            // Escritório sem empresa escolhida volta ao painel dele para clicar em "Acessar"
            '/empresa' => ['admin' => 403, 'escritorio' => 302, 'empresa' => 200],
        ];

        $usuarios = [
            'admin' => User::factory()->admin()->create(),
            'escritorio' => User::factory()->escritorio()->create(),
            'empresa' => User::factory()->create(),
        ];

        foreach ($matriz as $url => $esperado) {
            foreach ($usuarios as $perfil => $user) {
                $this->actingAs($user)->get($url)
                    ->assertStatus($esperado[$perfil], "Perfil {$perfil} em {$url}");
            }
        }
    }

    public function test_rota_painel_redireciona_conforme_o_perfil(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/painel')->assertRedirect('/admin');
        $this->actingAs(User::factory()->escritorio()->create())->get('/painel')->assertRedirect('/escritorio');
        $this->actingAs(User::factory()->create())->get('/painel')->assertRedirect('/empresa');
    }

    public function test_usuario_logado_nao_ve_tela_de_login(): void
    {
        $this->actingAs(User::factory()->create())->get('/entrar')->assertRedirect('/painel');
    }

    public function test_escritorio_so_enxerga_empresas_vinculadas_e_ativas(): void
    {
        $user = User::factory()->escritorio()->create();
        $escritorio = $user->escritorio;

        $ativa = Empresa::factory()->create();
        $pendente = Empresa::factory()->create();
        $outra = Empresa::factory()->create();

        $escritorio->empresas()->attach($ativa->id, ['status' => 'ativo']);
        $escritorio->empresas()->attach($pendente->id, ['status' => 'pendente']);

        $this->assertTrue($user->podeAcessarEmpresa($ativa));
        $this->assertFalse($user->podeAcessarEmpresa($pendente));
        $this->assertFalse($user->podeAcessarEmpresa($outra));
    }

    public function test_empresa_so_enxerga_a_propria_e_admin_todas(): void
    {
        $user = User::factory()->create();
        $outra = Empresa::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($user->podeAcessarEmpresa($user->empresa));
        $this->assertFalse($user->podeAcessarEmpresa($outra));
        $this->assertTrue($admin->podeAcessarEmpresa($outra));
    }

    public function test_paineis_mostram_os_dados_do_proprio_usuario(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/empresa')
            ->assertOk()
            ->assertSee($user->empresa->razao_social)
            ->assertSee('homologação');
    }
}
