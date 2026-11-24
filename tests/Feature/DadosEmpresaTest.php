<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DadosEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'razao_social' => 'Comércio Exemplo LTDA',
            'nome_fantasia' => '',
            'inscricao_estadual' => '082.560.668',
            'crt' => Empresa::CRT_SIMPLES,
            'cep' => '29010-000',
            'logradouro' => 'Avenida Jerônimo Monteiro',
            'numero' => '100',
            'complemento' => '',
            'bairro' => 'Centro',
            'municipio' => 'Vitória',
            'uf' => 'ES',
            'codigo_municipio' => '3205309',
            'email' => 'fiscal@exemplo.com.br',
            'telefone' => '',
            'serie_nfe' => 1,
            'proximo_numero_nfe' => 1,
        ], $extra);
    }

    public function test_tela_abre_com_regimes_futuros_desabilitados(): void
    {
        $this->actingAs($this->user)->get('/empresa/dados')
            ->assertOk()
            ->assertSee('Simples Nacional')
            ->assertSee('MEI')
            ->assertSee('Lucro Presumido e Lucro Real: em breve.');
    }

    public function test_salva_dados_guardando_so_numeros(): void
    {
        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['telefone' => '(27) 99999-1234']))
            ->assertRedirect('/empresa/dados')
            ->assertSessionHasNoErrors();

        $empresa = $this->user->empresa->fresh();

        $this->assertSame('29010000', $empresa->cep);
        $this->assertSame('082560668', $empresa->inscricao_estadual);
        $this->assertSame('27999991234', $empresa->telefone);
        $this->assertNull($empresa->nome_fantasia);
        $this->assertTrue($empresa->dadosCadastraisCompletos());
    }

    public function test_cnpj_pode_ser_corrigido_antes_da_primeira_nota(): void
    {
        $this->actingAs($this->user)->get('/empresa/dados')->assertSee('Pode ser corrigido até a primeira nota emitida.');

        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['cnpj' => '11.222.333/0001-81']))
            ->assertSessionHasNoErrors();

        $this->assertSame('11222333000181', $this->user->empresa->fresh()->cnpj);
    }

    public function test_cnpj_invalido_ou_de_outra_conta_e_recusado(): void
    {
        Empresa::factory()->create(['cnpj' => '11222333000181']);

        $this->actingAs($this->user)->put('/empresa/dados', $this->dados(['cnpj' => '11222333000182']))->assertSessionHasErrors('cnpj');
        $this->actingAs($this->user)->put('/empresa/dados', $this->dados(['cnpj' => '11222333000181']))->assertSessionHasErrors('cnpj');
    }

    public function test_trocar_cnpj_remove_certificado_de_outra_raiz(): void
    {
        $empresa = $this->user->empresa;
        \App\Models\Certificado::create(['empresa_id' => $empresa->id, 'arquivo_pfx' => 'x', 'senha' => 'y']);

        $this->actingAs($this->user)->put('/empresa/dados', $this->dados(['cnpj' => '11222333000181']));

        $this->assertSame(0, $empresa->certificados()->count());
    }

    public function test_cnpj_fica_travado_depois_de_emitir_nota(): void
    {
        $empresa = $this->user->empresa;
        $cnpj = $empresa->cnpj;
        Nota::create(['empresa_id' => $empresa->id, 'serie' => 1, 'numero' => 1, 'ambiente' => 2, 'status' => 'autorizada']);

        $this->actingAs($this->user)->get('/empresa/dados')->assertSee('A empresa já emitiu notas');
        $this->actingAs($this->user)->put('/empresa/dados', $this->dados(['cnpj' => '11222333000181']))->assertSessionHasErrors('cnpj');

        $this->assertSame($cnpj, $empresa->fresh()->cnpj);
    }

    public function test_inscricao_estadual_invalida_no_es_e_recusada(): void
    {
        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['inscricao_estadual' => '082560669']))
            ->assertSessionHasErrors('inscricao_estadual');
    }

    public function test_lucro_presumido_ainda_nao_e_aceito(): void
    {
        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['crt' => Empresa::CRT_NORMAL]))
            ->assertSessionHasErrors('crt');
    }

    public function test_campos_obrigatorios(): void
    {
        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['logradouro' => '', 'email' => '', 'cep' => '123']))
            ->assertSessionHasErrors(['logradouro', 'email', 'cep']);
    }

    public function test_sem_codigo_do_cep_busca_o_municipio_no_ibge(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 3205309, 'nome' => 'Vitória'],
                ['id' => 3201308, 'nome' => 'Cariacica'],
            ]),
        ]);

        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['codigo_municipio' => '', 'municipio' => 'cariacica']))
            ->assertSessionHasNoErrors();

        $this->assertSame('3201308', $this->user->empresa->fresh()->codigo_municipio);
    }

    public function test_cidade_inexistente_e_recusada(): void
    {
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([['id' => 3205309, 'nome' => 'Vitória']])]);

        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['codigo_municipio' => '', 'municipio' => 'Cidade Inventada']))
            ->assertSessionHasErrors('municipio');
    }

    public function test_numeracao_nao_volta_para_tras(): void
    {
        $empresa = $this->user->empresa;
        Nota::create(['empresa_id' => $empresa->id, 'serie' => 1, 'numero' => 50, 'ambiente' => $empresa->ambiente]);

        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['proximo_numero_nfe' => 50]))
            ->assertSessionHasErrors('proximo_numero_nfe');

        $this->actingAs($this->user)
            ->put('/empresa/dados', $this->dados(['proximo_numero_nfe' => 51]))
            ->assertSessionHasNoErrors();
    }

    public function test_painel_mostra_pendencias(): void
    {
        $this->actingAs($this->user)->get('/empresa')
            ->assertSee('Completar os dados da empresa')
            ->assertSee('Enviar o certificado digital A1');
    }

    public function test_tela_de_dados_usa_o_mesmo_formulario_do_cadastro(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->get('/empresa/dados')->assertOk()
            ->assertSee('Numeração das notas')->assertSee('Atualizar com os dados da Receita e da SEFAZ');
    }

    public function test_ultima_nota_informada_vira_o_proximo_numero(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->put('/empresa/dados', $this->dados(['ja_emitiu' => '1', 'serie_nfe' => 3, 'ultimo_numero_nfe' => 87]))
            ->assertSessionHasNoErrors();

        $this->assertSame([3, 88], [$user->empresa->fresh()->serie_nfe, $user->empresa->fresh()->proximo_numero_nfe]);
    }
}
