<?php

namespace Tests\Feature;

use App\Models\Destinatario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PessoaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function endereco(): array
    {
        return [
            'cep' => '29010-000', 'logradouro' => 'Avenida Jerônimo Monteiro', 'numero' => '100',
            'bairro' => 'Centro', 'municipio' => 'Vitória', 'uf' => 'ES', 'codigo_municipio' => '3205309',
        ];
    }

    private function pj(array $extra = []): array
    {
        return array_merge([
            'tipo_pessoa' => 'J', 'cpf_cnpj' => '11.222.333/0001-81', 'razao_social' => 'Cliente Exemplo LTDA',
            'nome_fantasia' => 'Exemplo', 'ind_ie_dest' => 1, 'inscricao_estadual' => '082560668',
        ], $this->endereco(), $extra);
    }

    private function pf(array $extra = []): array
    {
        return array_merge([
            'tipo_pessoa' => 'F', 'cpf_cnpj' => '529.982.247-25', 'razao_social' => 'Maria da Silva',
        ], $this->endereco(), $extra);
    }

    public function test_telas_abrem(): void
    {
        $this->actingAs($this->user)->get('/empresa/pessoas')->assertOk()->assertSee('Nenhuma pessoa cadastrada');
        $this->actingAs($this->user)->get('/empresa/pessoas/create')->assertOk()->assertSee('Pessoa física');
    }

    public function test_cadastra_pessoa_juridica_contribuinte(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj())
            ->assertRedirect('/empresa/pessoas')->assertSessionHasNoErrors();

        $p = Destinatario::first();
        $this->assertSame('11222333000181', $p->cpf_cnpj);
        $this->assertSame('Exemplo', $p->nome_fantasia);
        $this->assertSame('082560668', $p->inscricao_estadual);
        $this->assertSame(1, $p->ind_ie_dest);
        $this->assertSame($this->user->empresa_id, $p->empresa_id);
    }

    public function test_cadastra_pessoa_fisica_sempre_nao_contribuinte(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pf([
            'ind_ie_dest' => 1, 'inscricao_estadual' => '082560668', 'nome_fantasia' => 'ignorado',
        ]))->assertSessionHasNoErrors();

        $p = Destinatario::first();
        $this->assertSame('52998224725', $p->cpf_cnpj);
        $this->assertSame(9, $p->ind_ie_dest);
        $this->assertNull($p->inscricao_estadual);
        $this->assertNull($p->nome_fantasia);
        $this->assertSame('529.982.247-25', $p->documentoFormatado());
    }

    public function test_cpf_e_cnpj_invalidos(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pf(['cpf_cnpj' => '529.982.247-26']))->assertSessionHasErrors('cpf_cnpj');
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj(['cpf_cnpj' => '11.222.333/0001-82']))->assertSessionHasErrors('cpf_cnpj');
        // CPF no lugar de CNPJ
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj(['cpf_cnpj' => '52998224725']))->assertSessionHasErrors('cpf_cnpj');
    }

    public function test_contribuinte_exige_ie_e_isento_nao_aceita_ie(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj(['inscricao_estadual' => '']))->assertSessionHasErrors('inscricao_estadual');
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj(['ind_ie_dest' => 2]))->assertSessionHasErrors('inscricao_estadual');
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj(['ind_ie_dest' => 2, 'inscricao_estadual' => '']))->assertSessionHasNoErrors();
    }

    public function test_documento_repetido_e_recusado(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj());
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj())->assertSessionHasErrors('cpf_cnpj');

        $this->assertSame(1, Destinatario::count());
    }

    public function test_mesmo_documento_em_empresas_diferentes_e_permitido(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj());
        $this->actingAs(User::factory()->create())->post('/empresa/pessoas', $this->pj())->assertSessionHasNoErrors();

        $this->assertSame(2, Destinatario::count());
    }

    public function test_editar_e_excluir(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj());
        $id = Destinatario::first()->id;

        $this->actingAs($this->user)->put("/empresa/pessoas/{$id}", $this->pj(['razao_social' => 'Novo Nome LTDA']))
            ->assertRedirect('/empresa/pessoas');
        $this->assertSame('Novo Nome LTDA', Destinatario::first()->razao_social);

        $this->actingAs($this->user)->delete("/empresa/pessoas/{$id}")->assertRedirect('/empresa/pessoas');
        $this->assertSame(0, Destinatario::count());
    }

    public function test_recadastrar_pessoa_excluida_reaproveita_o_registro(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj());
        $id = Destinatario::first()->id;
        $this->actingAs($this->user)->delete("/empresa/pessoas/{$id}");

        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj(['razao_social' => 'Voltou LTDA']))->assertSessionHasNoErrors();

        $this->assertSame(1, Destinatario::count());
        $this->assertSame($id, Destinatario::first()->id);
    }

    public function test_nao_acessa_pessoa_de_outra_empresa(): void
    {
        $outro = User::factory()->create();
        $this->actingAs($outro)->post('/empresa/pessoas', $this->pj());
        $id = Destinatario::first()->id;

        $this->actingAs($this->user)->get("/empresa/pessoas/{$id}/edit")->assertNotFound();
        $this->actingAs($this->user)->delete("/empresa/pessoas/{$id}")->assertNotFound();
        $this->actingAs($this->user)->get('/empresa/pessoas')->assertDontSee('Cliente Exemplo LTDA');
    }

    public function test_busca_por_nome_e_documento(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj());
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pf());

        $this->actingAs($this->user)->get('/empresa/pessoas?busca=maria')->assertSee('Maria da Silva')->assertDontSee('Cliente Exemplo LTDA');
        $this->actingAs($this->user)->get('/empresa/pessoas?busca=11.222')->assertSee('Cliente Exemplo LTDA')->assertDontSee('Maria da Silva');
    }

    public function test_ie_do_es_sem_o_zero_a_esquerda_e_corrigida(): void
    {
        $this->actingAs($this->user)->post('/empresa/pessoas', $this->pj(['inscricao_estadual' => '82560668']))
            ->assertSessionHasNoErrors();

        $this->assertSame('082560668', Destinatario::first()->inscricao_estadual);
    }
}
