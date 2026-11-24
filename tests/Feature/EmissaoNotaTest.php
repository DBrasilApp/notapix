<?php

namespace Tests\Feature;

use App\Mail\NotaAutorizadaMail;
use App\Models\CreditoMovimento;
use App\Models\Destinatario;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeraCertificados;
use Tests\Concerns\SefazFalsa;
use Tests\TestCase;

class EmissaoNotaTest extends TestCase
{
    use GeraCertificados, RefreshDatabase, SefazFalsa;

    private User $user;

    private Empresa $empresa;

    private Destinatario $cliente;

    private Produto $produto;

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

        // Certificado de teste com o CNPJ da empresa
        $this->actingAs($this->user)->post('/empresa/certificado', [
            'arquivo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('cert.pfx', $this->gerarPfx('11222333000181')),
            'senha' => 'senha-cert',
        ])->assertSessionHasNoErrors();

        $this->creditos(2);

        $this->cliente = $this->empresa->destinatarios()->create([
            'tipo_pessoa' => 'J', 'cpf_cnpj' => '11444777000161', 'razao_social' => 'Cliente LTDA', 'ind_ie_dest' => 9,
            'email' => 'compras@cliente.com.br', 'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro',
            'codigo_municipio' => '3205309', 'municipio' => 'Vitoria', 'uf' => 'ES', 'cep' => '29010000',
        ]);
        $this->produto = $this->empresa->produtos()->create(Produto::PADROES_SIMPLES + [
            'codigo' => '1', 'descricao' => 'Camiseta de algodão', 'ncm' => '61091000', 'valor_unitario' => 49.90,
        ]);
    }

    private function creditos(int $quantidade): void
    {
        CreditoMovimento::create([
            'carteira_type' => 'empresa', 'carteira_id' => $this->empresa->id,
            'tipo' => CreditoMovimento::CORTESIA, 'quantidade' => $quantidade,
        ]);
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'destinatario_id' => $this->cliente->id,
            'natureza_operacao' => 'Venda de mercadoria',
            'consumidor_final' => '0',
            'modalidade_frete' => 9,
            'forma_pagamento' => '17',
            'ind_pagamento' => 0,
            'informacoes_complementares' => 'Pedido 123',
            'itens' => [
                ['produto_id' => $this->produto->id, 'quantidade' => '2', 'valor_unitario' => '49,90', 'desconto' => '5,00', 'cfop' => '5.102'],
            ],
        ], $extra);
    }

    private function emitir(array $extra = []): Nota
    {
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados($extra) + ['acao' => 'emitir']);

        return Nota::latest('id')->firstOrFail();
    }

    public function test_formulario_abre_com_pessoas_e_produtos(): void
    {
        $this->actingAs($this->user)->get('/empresa/notas/create')
            ->assertOk()
            ->assertSee('Cliente LTDA')
            ->assertSee('Camiseta de algod', false);
    }

    public function test_salva_rascunho_sem_gastar_credito_nem_numero(): void
    {
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados() + ['acao' => 'rascunho'])->assertSessionHasNoErrors();

        $nota = Nota::firstOrFail();
        $this->assertSame(Nota::RASCUNHO, $nota->status);
        $this->assertNull($nota->numero);
        $this->assertSame('94.80', $nota->valor_total);
        $this->assertSame('Cliente LTDA', $nota->nomeDestinatario());
        $this->assertSame(2, $this->empresa->saldoCreditos());

        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}")->assertOk()->assertSee('Rascunho')->assertSee('Emitir nota');
    }

    public function test_emite_e_autoriza(): void
    {
        $fabrica = $this->usarSefazFalsa('autorizada');

        $nota = $this->emitir();

        $this->assertSame(Nota::AUTORIZADA, $nota->status, (string) $nota->motivo);
        $this->assertSame(1, $nota->numero);
        $this->assertSame('332260000012345', $nota->protocolo);
        $this->assertSame(44, strlen($nota->chave));
        $this->assertSame(['autorizar'], $fabrica->chamadas);

        // Crédito consumido, numeração avançou
        $this->assertSame(1, $this->empresa->saldoCreditos());
        $this->assertSame(2, $this->empresa->fresh()->proximo_numero_nfe);

        // XML autorizado (com protocolo) e DANFE guardados
        Storage::disk('local')->assertExists($nota->xml_path);
        Storage::disk('local')->assertExists($nota->pdf_path);
        $this->assertStringContainsString('<nfeProc', Storage::disk('local')->get($nota->xml_path));
        $this->assertStringContainsString('<infCpl>DOCUMENTO EMITIDO POR ME OU EPP OPTANTE PELO SIMPLES NACIONAL', Storage::disk('local')->get($nota->xml_path));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($nota->pdf_path));

        // Downloads
        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}/xml")->assertOk();
        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}/danfe")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // E-mail para a empresa e para o cliente
        Mail::assertSent(NotaAutorizadaMail::class, fn ($m) => $m->hasTo('fiscal@empresa.com.br') && $m->hasTo('compras@cliente.com.br'));

        // Autorizada não pode mais ser alterada
        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}/edit")->assertRedirect("/empresa/notas/{$nota->id}");
    }

    public function test_rejeitada_devolve_credito_e_pode_ser_reenviada_com_o_mesmo_numero(): void
    {
        $this->usarSefazFalsa('rejeitada', 'autorizada');

        $nota = $this->emitir();
        $this->assertSame(Nota::REJEITADA, $nota->status);
        $this->assertSame(539, $nota->codigo_status);
        $this->assertSame(2, $this->empresa->saldoCreditos());
        $chave = $nota->chave;

        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}")->assertSee('O crédito foi devolvido');

        // Corrige e envia de novo
        $this->actingAs($this->user)->put("/empresa/notas/{$nota->id}", $this->dados(['natureza_operacao' => 'Venda']) + ['acao' => 'emitir']);

        $nota->refresh();
        $this->assertSame(Nota::AUTORIZADA, $nota->status);
        $this->assertSame(1, $nota->numero);
        $this->assertSame($chave, $nota->chave);
        $this->assertSame(1, $this->empresa->saldoCreditos());
        $this->assertSame(2, $this->empresa->fresh()->proximo_numero_nfe);
    }

    public function test_falha_de_comunicacao_deixa_processando_e_consulta_resolve(): void
    {
        $fabrica = $this->usarSefazFalsa('erro', 'autorizada');

        $nota = $this->emitir();
        $this->assertSame(Nota::PROCESSANDO, $nota->status);
        $this->assertSame(1, $this->empresa->saldoCreditos()); // continua reservado

        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/consultar");

        $this->assertSame(Nota::AUTORIZADA, $nota->fresh()->status);
        $this->assertSame(['autorizar', 'consultar'], $fabrica->chamadas);
        $this->assertSame(1, $this->empresa->saldoCreditos());
    }

    public function test_duplicidade_busca_o_protocolo_pela_consulta(): void
    {
        $fabrica = $this->usarSefazFalsa('duplicidade', 'autorizada');

        $nota = $this->emitir();

        $this->assertSame(Nota::AUTORIZADA, $nota->status);
        $this->assertSame(['autorizar', 'consultar'], $fabrica->chamadas);
    }

    public function test_consulta_de_nota_que_a_sefaz_nao_recebeu_libera_reenvio(): void
    {
        $this->usarSefazFalsa('erro', 'nao_encontrada');

        $nota = $this->emitir();
        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/consultar");

        $nota->refresh();
        $this->assertSame(Nota::REJEITADA, $nota->status);
        $this->assertSame(2, $this->empresa->saldoCreditos());
    }

    public function test_denegada_consome_o_credito(): void
    {
        $this->usarSefazFalsa('denegada');

        $nota = $this->emitir();

        $this->assertSame(Nota::DENEGADA, $nota->status);
        $this->assertSame(1, $this->empresa->saldoCreditos());
        $this->assertFalse($nota->podeEditar());
    }

    public function test_sem_credito_nao_envia_e_nao_gasta_numero(): void
    {
        $this->usarSefazFalsa();
        CreditoMovimento::create(['carteira_type' => 'empresa', 'carteira_id' => $this->empresa->id, 'tipo' => CreditoMovimento::AJUSTE, 'quantidade' => -2]);

        $nota = $this->emitir();

        $this->assertSame(Nota::RASCUNHO, $nota->status);
        $this->assertNull($nota->numero);
        $this->assertSame(1, $this->empresa->fresh()->proximo_numero_nfe);
        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}")->assertSee('não tem créditos');
    }

    public function test_sem_certificado_nao_envia(): void
    {
        $this->usarSefazFalsa();
        $this->empresa->certificados()->delete();

        $nota = $this->emitir();

        $this->assertSame(Nota::RASCUNHO, $nota->status);
        $this->assertSame(2, $this->empresa->saldoCreditos());
    }

    public function test_csosn_ainda_nao_suportado_e_avisado_antes_de_enviar(): void
    {
        $fabrica = $this->usarSefazFalsa();
        $this->produto->update(['cst_csosn' => '101']);

        $nota = $this->emitir();

        $this->assertSame(Nota::RASCUNHO, $nota->status);
        $this->assertSame([], $fabrica->chamadas);
        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}")->assertSee('CSOSN 101 ainda não é suportado');
    }

    public function test_cliente_de_outro_estado_troca_o_cfop_para_6(): void
    {
        $this->usarSefazFalsa('autorizada');
        $this->cliente->update(['uf' => 'MG', 'codigo_municipio' => '3106200', 'municipio' => 'Belo Horizonte', 'ind_ie_dest' => 1, 'inscricao_estadual' => '0623079040081']);

        $nota = $this->emitir();

        $this->assertSame(2, $nota->id_dest);
        $this->assertSame('6102', $nota->itens()->first()->cfop);
        $this->assertSame(Nota::AUTORIZADA, $nota->status, (string) $nota->motivo);
    }

    public function test_consumidor_final_nao_contribuinte_de_outro_estado_e_bloqueado(): void
    {
        $this->usarSefazFalsa();
        $this->cliente->update(['uf' => 'MG', 'codigo_municipio' => '3106200', 'municipio' => 'Belo Horizonte']);

        $nota = $this->emitir();

        $this->assertSame(Nota::RASCUNHO, $nota->status);
    }

    public function test_validacoes_do_formulario(): void
    {
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados(['itens' => []]) + ['acao' => 'rascunho'])
            ->assertSessionHasErrors('itens');
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados(['forma_pagamento' => '99']) + ['acao' => 'rascunho'])
            ->assertSessionHasErrors('descricao_pagamento');
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados(['itens' => [
            ['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario' => '10,00', 'desconto' => '10,00', 'cfop' => '5102'],
        ]]) + ['acao' => 'rascunho'])->assertSessionHasErrors('itens.0.desconto');
    }

    public function test_isolamento_entre_empresas_e_exclusao_de_rascunho(): void
    {
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados() + ['acao' => 'rascunho']);
        $nota = Nota::firstOrFail();

        $this->actingAs(User::factory()->create())->get("/empresa/notas/{$nota->id}")->assertNotFound();

        $this->actingAs($this->user)->delete("/empresa/notas/{$nota->id}")->assertRedirect('/empresa/notas');
        $this->assertSame(0, Nota::count());
    }

    public function test_comando_concede_creditos(): void
    {
        $this->artisan('creditos:conceder', ['cnpj' => '11.222.333/0001-81', 'quantidade' => 5])->assertSuccessful();

        $this->assertSame(7, $this->empresa->saldoCreditos());
    }
}
