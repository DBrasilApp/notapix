<?php

namespace Tests\Feature;

use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TabelaNcmFalsa;
use Tests\TestCase;

class ProdutoTest extends TestCase
{
    use RefreshDatabase, TabelaNcmFalsa;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'codigo' => '', 'descricao' => 'Camiseta de algodão', 'unidade' => 'un', 'valor_unitario' => '1.234,50',
            'gtin' => '', 'ncm' => '6109.10.00', 'cest' => '', 'cfop_padrao' => '5102', 'origem' => 0,
            'cst_csosn' => '102', 'cst_pis' => '49', 'cst_cofins' => '49', 'cst_ibs_cbs' => '', 'cclass_trib' => '',
        ], $extra);
    }

    public function test_formulario_vem_com_padroes_do_simples(): void
    {
        $this->actingAs($this->user)->get('/empresa/produtos/create')
            ->assertOk()
            ->assertSee('value="5.102"', false)
            ->assertSee('102 - Tributada sem permissão de crédito')
            ->assertSee('A pesquisa de NCM ainda não está disponível');
    }

    public function test_cadastra_produto_com_codigo_automatico(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados())->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['descricao' => 'Outra']))->assertSessionHasNoErrors();

        $produtos = Produto::orderBy('id')->get();
        $this->assertSame(['1', '2'], $produtos->pluck('codigo')->all());

        $p = $produtos->first();
        $this->assertSame('UN', $p->unidade);
        $this->assertSame('1234.5000', $p->valor_unitario);
        $this->assertSame('SEM GTIN', $p->gtin);
        $this->assertSame('61091000', $p->ncm);
        $this->assertSame('R$ 1.234,50', $p->precoFormatado());
    }

    public function test_ncm_reduzido_e_recusado(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['ncm' => '6109']))
            ->assertSessionHasErrors(['ncm' => 'O NCM deve ter 8 dígitos. Códigos reduzidos não são aceitos na NF-e.']);
    }

    public function test_com_tabela_importada_ncm_inexistente_ou_vencido_e_recusado(): void
    {
        $this->importarNcmFalsa();

        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['ncm' => '12345678']))->assertSessionHasErrors('ncm');
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['ncm' => '09019999']))->assertSessionHasErrors('ncm');
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados())->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get('/empresa/produtos/'.Produto::first()->id.'/edit')
            ->assertSee('Camisetas ("T-shirts") e camisetas interiores, de malha › De algodão');
    }

    public function test_codigo_de_barras_validado(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['gtin' => '7891000315507']))->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['gtin' => '7891000315508']))->assertSessionHasErrors('gtin');
    }

    public function test_cfop_de_entrada_e_csosn_invalido_sao_recusados(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['cfop_padrao' => '1102']))->assertSessionHasErrors('cfop_padrao');
        // Existe na tabela, mas é de serviço de comunicação: não pode ir na NF-e
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['cfop_padrao' => '5301']))->assertSessionHasErrors('cfop_padrao');
        // Inexistente
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['cfop_padrao' => '5999']))->assertSessionHasErrors('cfop_padrao');
        // Outros CFOPs de saída válidos: ST, devolução de compra, bonificação
        foreach (['5.405', '5202', '5910'] as $cfop) {
            $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['cfop_padrao' => $cfop]))->assertSessionHasNoErrors();
        }
        $this->assertSame(['5405', '5202', '5910'], Produto::orderBy('id')->pluck('cfop_padrao')->all());
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['cst_csosn' => '00']))->assertSessionHasErrors('cst_csosn');
    }

    public function test_campos_ibs_cbs_opcionais_mas_validados(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['cclass_trib' => '12']))->assertSessionHasErrors('cclass_trib');
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['cclass_trib' => '999999']))->assertSessionHasErrors('cclass_trib');
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['cst_ibs_cbs' => '410', 'cclass_trib' => '200003']))->assertSessionHasNoErrors();
        // O CST vem da classificação, não do que foi digitado
        $this->assertSame('200', \App\Models\Produto::where('cclass_trib', '200003')->value('cst_ibs_cbs'));
    }

    public function test_unidade_precisa_ser_da_lista(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['unidade' => 'XYZ']))->assertSessionHasErrors('unidade');
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['unidade' => 'gl']))->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get('/empresa/produtos/create')->assertSee('GL - Galão')->assertSee('M2 - Metro quadrado');
    }

    public function test_preco_obrigatorio_e_maior_que_zero(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['valor_unitario' => '']))->assertSessionHasErrors('valor_unitario');
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['valor_unitario' => '0,00']))->assertSessionHasErrors('valor_unitario');
    }

    public function test_preco_aparece_na_edicao(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['valor_unitario' => '18,90']));

        $this->actingAs($this->user)->get('/empresa/produtos/'.Produto::first()->id.'/edit')
            ->assertSee('value="18,90"', false)
            ->assertSee('Venda de mercadoria adquirida ou recebida de terceiros');
    }

    public function test_codigo_e_sempre_automatico_e_nao_muda(): void
    {
        $this->actingAs($this->user)->get('/empresa/produtos/create')->assertSee('Gerado automaticamente ao salvar.');

        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['codigo' => 'MEU-CODIGO']));
        $p = Produto::first();
        $this->assertSame('1', $p->codigo);

        $this->actingAs($this->user)->put("/empresa/produtos/{$p->id}", $this->dados(['codigo' => '999']));
        $this->assertSame('1', $p->fresh()->codigo);

        // Excluído não libera o número: o próximo continua a sequência
        $this->actingAs($this->user)->delete("/empresa/produtos/{$p->id}");
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados());
        $this->assertSame('2', Produto::first()->codigo);
    }

    public function test_editar_excluir_e_isolamento_entre_empresas(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados());
        $id = Produto::first()->id;

        $this->actingAs(User::factory()->create())->get("/empresa/produtos/{$id}/edit")->assertNotFound();

        $this->actingAs($this->user)->put("/empresa/produtos/{$id}", $this->dados(['descricao' => 'Camiseta azul', 'codigo' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Camiseta azul', Produto::first()->descricao);
        $this->assertSame('1', Produto::first()->codigo);

        $this->actingAs($this->user)->delete("/empresa/produtos/{$id}");
        $this->assertSame(0, Produto::count());
    }

    public function test_listagem_e_busca(): void
    {
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados());
        $this->actingAs($this->user)->post('/empresa/produtos', $this->dados(['descricao' => 'Café torrado', 'ncm' => '09012100']));

        $this->actingAs($this->user)->get('/empresa/produtos')->assertSee('Camiseta de algodão')->assertSee('6109.10.00');
        $this->actingAs($this->user)->get('/empresa/produtos?busca=café')->assertSee('Café torrado')->assertDontSee('Camiseta de algodão');
        $this->actingAs($this->user)->get('/empresa/produtos?busca=0901')->assertSee('Café torrado')->assertDontSee('Camiseta de algodão');
    }
}
