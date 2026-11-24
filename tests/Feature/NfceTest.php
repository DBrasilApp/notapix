<?php

namespace Tests\Feature;

use App\Models\CreditoMovimento;
use App\Models\Destinatario;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeraCertificados;
use Tests\Concerns\SefazFalsa;
use Tests\TestCase;

/** NFC-e (modelo 65): venda rápida, cupom, pagamentos com troco e cancelamento em 30 minutos. */
class NfceTest extends TestCase
{
    use GeraCertificados, RefreshDatabase, SefazFalsa;

    private User $user;

    private Empresa $empresa;

    private Destinatario $fornecedor;

    private Produto $produto;

    private string $pfx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();

        $this->empresa = Empresa::factory()->create([
            'cnpj' => '11222333000181', 'razao_social' => 'EMPRESA TESTE LTDA', 'inscricao_estadual' => '082560668',
            'crt' => Empresa::CRT_SIMPLES, 'logradouro' => 'Av Jeronimo Monteiro', 'numero' => '100', 'bairro' => 'Centro',
            'codigo_municipio' => '3205309', 'municipio' => 'Vitoria', 'uf' => 'ES', 'cep' => '29010000',
            'email' => 'fiscal@empresa.com.br', 'serie_nfe' => 1, 'proximo_numero_nfe' => 1,
        ]);
        $this->user = User::factory()->create(['empresa_id' => $this->empresa->id]);

        $this->pfx = $this->gerarPfx('11222333000181');
        $this->actingAs($this->user)->post('/empresa/certificado', [
            'arquivo' => UploadedFile::fake()->createWithContent('cert.pfx', $this->pfx),
            'senha' => 'senha-cert',
        ])->assertSessionHasNoErrors();

        CreditoMovimento::create([
            'carteira_type' => 'empresa', 'carteira_id' => $this->empresa->id,
            'tipo' => CreditoMovimento::CORTESIA, 'quantidade' => 5,
        ]);

        $this->fornecedor = $this->empresa->destinatarios()->create([
            'tipo_pessoa' => 'J', 'cpf_cnpj' => '11444777000161', 'razao_social' => 'Distribuidora LTDA',
            'ind_ie_dest' => 1, 'inscricao_estadual' => '081234565',
            'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro',
            'codigo_municipio' => '3205309', 'municipio' => 'Vitoria', 'uf' => 'ES', 'cep' => '29010000',
        ]);
        $this->produto = $this->empresa->produtos()->create(Produto::PADROES_SIMPLES + [
            'codigo' => '1', 'descricao' => 'Camiseta de algodão', 'ncm' => '61091000', 'valor_unitario' => 49.90,
        ]);
    }

    private function venda(array $extra = []): \Illuminate\Testing\TestResponse
    {
        $bone = $this->empresa->produtos()->firstOrCreate(['codigo' => '2'], Produto::PADROES_SIMPLES + [
            'descricao' => 'Boné azul', 'ncm' => '65050019', 'valor_unitario' => 37.50,
        ]);

        return $this->actingAs($this->user)->post('/empresa/nfce', array_merge([
            'itens' => [
                ['produto_id' => $this->produto->id, 'quantidade' => '1'],   // 49,90
                ['produto_id' => $bone->id, 'quantidade' => '1'],            // 37,50
            ],
            'pagamentos' => [['forma' => '01', 'valor' => '100,00']],
        ], $extra));
    }

    private function xmlEnviado(Nota $nota): string
    {
        return (string) Storage::disk('local')->get(preg_replace('/procNFe\.xml$/', 'nfe.xml', $nota->xml_path));
    }

    public function test_tela_de_venda_rapida(): void
    {
        $this->actingAs($this->user)->get('/empresa/nfce/venda')->assertOk()
            ->assertSee('Venda rápida')->assertSee('Camiseta de algod', false)->assertSee('Emitir NFC-e');
    }

    public function test_emite_nfce_com_troco_e_numeracao_propria(): void
    {
        $this->empresa->update(['nfce_serie' => 2, 'nfce_proximo_numero' => 10]);
        $fabrica = $this->usarSefazFalsa('autorizada');

        $resposta = $this->venda();
        $nota = Nota::firstOrFail();
        $resposta->assertRedirect("/empresa/nfce/{$nota->id}");

        $this->assertSame(Nota::AUTORIZADA, $nota->status, (string) $nota->motivo);
        $this->assertSame([65, 2, 10], [(int) $nota->modelo, (int) $nota->serie, (int) $nota->numero]);
        $this->assertSame(['87.40', '12.60'], [$nota->valor_total, $nota->troco]);
        $this->assertSame([65], $fabrica->modelos);

        $empresa = $this->empresa->fresh();
        $this->assertSame([11, 1], [$empresa->nfce_proximo_numero, $empresa->proximo_numero_nfe]); // NF-e não mexe
        $this->assertSame(4, $empresa->saldoCreditos());

        $xml = $this->xmlEnviado($nota);
        $this->assertStringContainsString('<mod>65</mod>', $xml);
        $this->assertStringContainsString('<tpImp>4</tpImp>', $xml);
        $this->assertStringContainsString('<indFinal>1</indFinal>', $xml);
        $this->assertStringContainsString('<vTroco>12.60</vTroco>', $xml);
        $this->assertStringContainsString('<qrCode>', $xml);       // QR Code (versão 3 em homologação, sem CSC)
        $this->assertStringContainsString('<urlChave>', $xml);
        $this->assertStringNotContainsString('<dest>', $xml);      // consumidor não identificado
        $this->assertStringContainsString('NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO', $xml);

        // Cupom (PDF em bobina) e tela
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($nota->pdf_path));
        $this->actingAs($this->user)->get("/empresa/nfce/{$nota->id}")->assertOk()->assertSee('Imprimir cupom')->assertSee('Cancelar NFC-e')
            ->assertSee('wa.me/?text=', false)->assertSee(rawurlencode('http://homologacao.sefaz.es.gov.br/ConsultaNFCe/qrcode.aspx?p='), false);
        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}")->assertRedirect("/empresa/nfce/{$nota->id}");
        $this->actingAs($this->user)->get('/empresa/notas')->assertDontSee('Consumidor não identificado');
        $this->actingAs($this->user)->get('/empresa/nfce')->assertSee('Consumidor não identificado');
    }

    public function test_consumidor_identificado_e_dois_pagamentos(): void
    {
        $this->usarSefazFalsa('autorizada');

        $this->venda([
            'cpf_cnpj' => '529.982.247-25', 'nome' => 'João da Silva',
            'pagamentos' => [['forma' => '17', 'valor' => '50,00'], ['forma' => '04', 'valor' => '37,40']],
        ])->assertSessionHasNoErrors();

        $nota = Nota::firstOrFail();
        $this->assertSame(Nota::AUTORIZADA, $nota->status, (string) $nota->motivo);
        $xml = $this->xmlEnviado($nota);
        $this->assertStringContainsString('<CPF>52998224725</CPF>', $xml);
        $this->assertSame(2, substr_count($xml, '<detPag>'));
        $this->assertStringContainsString('<tpIntegra>2</tpIntegra>', $xml); // cartão não integrado
    }

    public function test_pagamento_errado_e_cpf_invalido_sao_recusados(): void
    {
        $this->venda(['pagamentos' => [['forma' => '01', 'valor' => '50,00']]])->assertSessionHasErrors('pagamentos');
        $this->venda(['pagamentos' => [['forma' => '17', 'valor' => '100,00']]])->assertSessionHasErrors('pagamentos'); // troco no Pix
        $this->venda(['cpf_cnpj' => '111.111.111-11'])->assertSessionHasErrors('cpf_cnpj');
        $this->assertSame(0, Nota::count());
    }

    public function test_cfop_do_produto(): void
    {
        $this->usarSefazFalsa('autorizada');
        $this->produto->update(['cfop_padrao' => '6102']);
        $this->actingAs($this->user)->post('/empresa/nfce', [
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']],
            'pagamentos' => [['forma' => '01', 'valor' => '49,90']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('5102', Nota::first()->itens->first()->cfop); // balcão é sempre dentro do estado

        $this->produto->update(['cfop_padrao' => '5949']);
        $this->actingAs($this->user)->post('/empresa/nfce', [
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']],
            'pagamentos' => [['forma' => '01', 'valor' => '49,90']],
        ])->assertSessionHasErrors('itens.0.produto_id');
    }

    public function test_cancela_em_ate_30_minutos(): void
    {
        $this->usarSefazFalsa('autorizada', 'registrado');
        $this->venda();
        $nota = Nota::firstOrFail();

        $nota->update(['autorizada_em' => now()->subMinutes(31)]);
        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/cancelar", ['justificativa' => 'Cliente desistiu da compra'])
            ->assertSessionHasErrors('cancelamento');

        $nota->update(['autorizada_em' => now()->subMinutes(10)]);
        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/cancelar", ['justificativa' => 'Cliente desistiu da compra'])
            ->assertRedirect("/empresa/nfce/{$nota->id}");
        $this->assertSame(Nota::CANCELADA, $nota->fresh()->status);
    }

    public function test_nfce_rejeitada_volta_para_o_caixa_com_o_mesmo_numero(): void
    {
        $this->usarSefazFalsa('rejeitada', 'autorizada');
        $this->venda();
        $nota = Nota::firstOrFail();
        $this->assertSame(Nota::REJEITADA, $nota->status);

        $this->actingAs($this->user)->get("/empresa/nfce/{$nota->id}/editar")->assertOk()->assertSee('Corrigindo a NFC-e');
        $this->actingAs($this->user)->put("/empresa/nfce/{$nota->id}", [
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '2']],
            'pagamentos' => [['forma' => '01', 'valor' => '99,80']],
        ])->assertRedirect("/empresa/nfce/{$nota->id}");

        $nota->refresh();
        $this->assertSame([Nota::AUTORIZADA, 1], [$nota->status, (int) $nota->numero]);
    }

    public function test_configuracao_do_csc_em_dados_da_empresa(): void
    {
        $dados = $this->empresa->only([
            'razao_social', 'inscricao_estadual', 'crt', 'cep', 'logradouro', 'numero', 'bairro', 'municipio', 'uf',
            'codigo_municipio', 'email',
        ]) + ['serie_nfe' => 1, 'proximo_numero_nfe' => 1, 'nfce_serie' => 2, 'nfce_ultimo_numero' => 40, 'nfce_bobina' => 58,
            'nfce_csc_homologacao' => 'ABCD1234ABCD1234ABCD1234ABCD1234', 'nfce_csc_id_homologacao' => '000001'];

        $this->actingAs($this->user)->put('/empresa/dados', $dados)->assertSessionHasNoErrors();
        $empresa = $this->empresa->fresh();
        $this->assertSame(['ABCD1234ABCD1234ABCD1234ABCD1234', '000001', 2, 41, 58],
            [$empresa->nfce_csc_homologacao, $empresa->nfce_csc_id_homologacao, $empresa->nfce_serie, $empresa->nfce_proximo_numero, $empresa->nfce_bobina]);
        $this->assertNotSame('ABCD1234ABCD1234ABCD1234ABCD1234', \DB::table('empresas')->value('nfce_csc_homologacao')); // criptografado

        // Em branco: mantém o CSC guardado
        $this->actingAs($this->user)->put('/empresa/dados', ['nfce_csc_homologacao' => ''] + $dados)->assertSessionHasNoErrors();
        $this->assertSame('ABCD1234ABCD1234ABCD1234ABCD1234', $this->empresa->fresh()->nfce_csc_homologacao);
    }

    public function test_remover_csc_e_aviso_de_id_faltando(): void
    {
        $this->empresa->update(['nfce_csc_homologacao' => 'ABCD1234ABCD1234ABCD1234ABCD1234', 'nfce_csc_id_homologacao' => null]);
        $this->user->unsetRelation('empresa');

        $this->actingAs($this->user)->get('/empresa/dados')->assertSee('1234')->assertSee('Falta o ID do CSC.');
        $this->venda()->assertSessionHasErrors('emissao');
        $this->assertStringContainsString('Falta o ID do CSC de homologação', session('errors')->first('emissao'));

        $dados = $this->empresa->only([
            'razao_social', 'inscricao_estadual', 'crt', 'cep', 'logradouro', 'numero', 'bairro', 'municipio', 'uf',
            'codigo_municipio', 'email',
        ]) + ['serie_nfe' => 1, 'proximo_numero_nfe' => 1, 'nfce_remover_csc_homologacao' => '1'];
        $this->actingAs($this->user)->put('/empresa/dados', $dados)->assertSessionHasNoErrors();
        $this->assertNull($this->empresa->fresh()->nfce_csc_homologacao);
    }

    public function test_cupom_em_72_mm_e_refeito_ao_imprimir(): void
    {
        $this->usarSefazFalsa('autorizada');
        $this->venda();
        $nota = Nota::firstOrFail();
        $antes = Storage::disk('local')->get($nota->pdf_path);

        $this->empresa->update(['nfce_bobina' => 72]);
        $this->user->unsetRelation('empresa');
        $pdf = $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}/danfe");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertNotSame($antes, Storage::disk('local')->get($nota->fresh()->pdf_path));
    }
}
