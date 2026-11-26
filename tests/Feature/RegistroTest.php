<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistroTest extends TestCase
{
    use RefreshDatabase;

    private function dados(array $extra = []): array
    {
        return array_merge([
            'tipo' => 'empresa',
            'cnpj' => '11.222.333/0001-81',
            'razao_social' => 'Comércio Exemplo LTDA',
            'name' => 'Maria Silva',
            'email' => 'maria@exemplo.com.br',
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
            'aceite' => '1',
            'nome_fantasia' => 'Exemplo', 'inscricao_estadual' => '082560668', 'crt' => 1,
            'cep' => '29010-000', 'logradouro' => 'Av Jeronimo Monteiro', 'numero' => '100', 'bairro' => 'Centro',
            'municipio' => 'Vitória', 'uf' => 'ES', 'codigo_municipio' => '3205309', 'telefone' => '(27) 3333-4444',
        ], $extra);
    }

    public function test_tela_de_cadastro_abre(): void
    {
        $this->get('/cadastro')->assertOk()->assertSee('Sou empresa')->assertSee('Numeração das notas');
        $this->get('/cadastro?tipo=escritorio')->assertOk()->assertSee('Nome do escritório (ou do contador)')->assertSee('CPF ou CNPJ');
    }

    public function test_empresa_se_cadastra_em_homologacao_e_aceite_e_registrado(): void
    {
        $this->post('/cadastro', $this->dados())->assertRedirect('/empresa/certificado');

        $user = User::where('email', 'maria@exemplo.com.br')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(Perfil::Empresa, $user->perfil);
        $this->assertSame('11222333000181', $user->empresa->cnpj);
        $this->assertSame(Empresa::AMBIENTE_HOMOLOGACAO, $user->empresa->ambiente);
        $this->assertSame('ES', $user->empresa->uf);
        $this->assertSame(2, $user->aceitesTermos()->count());

        // Cadastro completo já na criação da conta: só falta o certificado
        $empresa = $user->empresa;
        $this->assertSame('082560668', $empresa->inscricao_estadual);
        $this->assertSame('3205309', $empresa->codigo_municipio);
        $this->assertSame('2733334444', $empresa->telefone);
        $this->assertSame('maria@exemplo.com.br', $empresa->email);
        $this->assertTrue($empresa->dadosCadastraisCompletos());
    }

    public function test_numeracao_no_cadastro(): void
    {
        // Nunca emitiu: começa no 1
        $this->post('/cadastro', $this->dados(['ja_emitiu' => '0', 'ultimo_numero_nfe' => '999']))->assertRedirect('/empresa/certificado');
        $empresa = Empresa::firstOrFail();
        $this->assertSame([1, 1], [$empresa->serie_nfe, $empresa->proximo_numero_nfe]);
    }

    public function test_numeracao_continua_de_outro_sistema(): void
    {
        $this->post('/cadastro', $this->dados(['ja_emitiu' => '1', 'serie_nfe' => '2', 'ultimo_numero_nfe' => '1530']))
            ->assertRedirect('/empresa/certificado');
        $empresa = Empresa::firstOrFail();
        $this->assertSame([2, 1531], [$empresa->serie_nfe, $empresa->proximo_numero_nfe]);
    }

    public function test_ja_emitiu_sem_informar_o_numero_e_recusado(): void
    {
        $this->post('/cadastro', $this->dados(['ja_emitiu' => '1', 'serie_nfe' => '1', 'ultimo_numero_nfe' => '']))
            ->assertSessionHasErrors('proximo_numero_nfe');
        $this->assertGuest();
    }

    public function test_empresa_precisa_informar_os_dados_completos(): void
    {
        $this->post('/cadastro', $this->dados(['inscricao_estadual' => '123', 'cep' => '', 'crt' => 3]))
            ->assertSessionHasErrors(['inscricao_estadual', 'cep', 'crt']);
        $this->assertGuest();
    }

    public function test_empresa_so_de_servicos_pode_se_cadastrar_sem_inscricao_estadual(): void
    {
        $this->post('/cadastro', $this->dados(['inscricao_estadual' => '']))->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $this->assertNull(\App\Models\Empresa::first()->inscricao_estadual);
    }

    public function test_consulta_cnpj_publica_preenche_e_indica_o_regime(): void
    {
        \Illuminate\Support\Facades\Http::fake(['brasilapi.com.br/*' => \Illuminate\Support\Facades\Http::response([
            'razao_social' => 'COMERCIO EXEMPLO LTDA', 'logradouro' => 'RUA A', 'descricao_tipo_de_logradouro' => 'RUA',
            'numero' => '1', 'bairro' => 'CENTRO', 'municipio' => 'VITORIA', 'uf' => 'ES', 'cep' => '29010000',
            'codigo_municipio_ibge' => 3205309, 'descricao_situacao_cadastral' => 'ATIVA',
            'opcao_pelo_simples' => true, 'opcao_pelo_mei' => true,
        ])]);

        $this->getJson('/cadastro/cnpj/11222333000181')->assertOk()
            ->assertJson(['razao_social' => 'COMERCIO EXEMPLO LTDA', 'crt' => Empresa::CRT_MEI]);

        Empresa::factory()->create(['cnpj' => '11444777000161']);
        $this->getJson('/cadastro/cnpj/11444777000161')->assertStatus(409);
    }

    public function test_escritorio_se_cadastra(): void
    {
        $this->post('/cadastro', $this->dados(['tipo' => 'escritorio']))->assertRedirect('/escritorio');

        $user = User::where('email', 'maria@exemplo.com.br')->firstOrFail();

        $this->assertSame(Perfil::Escritorio, $user->perfil);
        $this->assertSame('Comércio Exemplo LTDA', $user->escritorio->razao_social);
        $this->assertNull($user->empresa_id);
    }

    public function test_contador_autonomo_se_cadastra_com_cpf(): void
    {
        $this->post('/cadastro', $this->dados(['tipo' => 'escritorio', 'cnpj' => '529.982.247-25', 'razao_social' => 'José Contador']))
            ->assertRedirect('/escritorio');

        $user = User::where('email', 'maria@exemplo.com.br')->firstOrFail();
        $this->assertSame('52998224725', $user->escritorio->cnpj);

        $this->actingAs($user)->get('/escritorio')->assertOk()->assertSee('CPF 529.982.247-25');
    }

    public function test_cpf_invalido_e_recusado_e_empresa_continua_so_com_cnpj(): void
    {
        $this->post('/cadastro', $this->dados(['tipo' => 'escritorio', 'cnpj' => '111.222.333-44']))->assertSessionHasErrors('cnpj');
        $this->post('/cadastro', $this->dados(['cnpj' => '529.982.247-25']))->assertSessionHasErrors('cnpj');
        $this->assertGuest();
    }

    public function test_nao_e_possivel_se_cadastrar_como_admin(): void
    {
        $this->post('/cadastro', $this->dados(['tipo' => 'admin']))->assertSessionHasErrors('tipo');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cnpj_invalido_e_recusado(): void
    {
        $this->post('/cadastro', $this->dados(['cnpj' => '11.222.333/0001-82']))->assertSessionHasErrors('cnpj');
        $this->assertGuest();
    }

    public function test_cnpj_repetido_e_recusado(): void
    {
        Empresa::factory()->create(['cnpj' => '11222333000181']);

        $this->post('/cadastro', $this->dados())->assertSessionHasErrors('cnpj');
    }

    public function test_sem_aceite_dos_termos_nao_cadastra(): void
    {
        $this->post('/cadastro', $this->dados(['aceite' => null]))->assertSessionHasErrors('aceite');
        $this->assertDatabaseCount('empresas', 0);
    }

    public function test_senha_fraca_e_recusada(): void
    {
        $this->post('/cadastro', $this->dados(['password' => 'abc', 'password_confirmation' => 'abc']))
            ->assertSessionHasErrors('password');
    }
}
