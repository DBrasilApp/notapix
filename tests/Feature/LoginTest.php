<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_de_login_abre(): void
    {
        $this->get('/entrar')->assertOk()->assertSee('Entrar');
    }

    public function test_cada_perfil_cai_no_seu_painel_apos_login(): void
    {
        $casos = [
            [User::factory()->admin()->create(), '/admin'],
            [User::factory()->escritorio()->create(), '/escritorio'],
            [User::factory()->create(), '/empresa'],
        ];

        foreach ($casos as [$user, $destino]) {
            $this->post('/entrar', ['email' => $user->email, 'password' => 'senha1234'])
                ->assertRedirect($destino);

            $this->assertAuthenticatedAs($user);
            $this->assertNotNull($user->fresh()->ultimo_acesso_em);

            $this->post('/sair');
        }
    }

    public function test_senha_errada_nao_entra(): void
    {
        $user = User::factory()->create();

        $this->post('/entrar', ['email' => $user->email, 'password' => 'errada'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_bloqueado_nao_entra(): void
    {
        $user = User::factory()->bloqueado()->create();

        $this->post('/entrar', ['email' => $user->email, 'password' => 'senha1234'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_bloqueado_durante_a_sessao_e_desconectado(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->update(['ativo' => false]);

        $this->get('/empresa')->assertRedirect('/entrar');
        $this->assertGuest();
    }

    public function test_muitas_tentativas_bloqueiam_o_login(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/entrar', ['email' => $user->email, 'password' => 'errada']);
        }

        $this->post('/entrar', ['email' => $user->email, 'password' => 'senha1234'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_sair_encerra_a_sessao(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/sair')->assertRedirect('/entrar');
        $this->assertGuest();
    }
}
