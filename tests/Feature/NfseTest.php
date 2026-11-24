<?php

namespace Tests\Feature;

use App\Models\CreditoMovimento;
use App\Models\Destinatario;
use App\Models\Empresa;
use App\Models\Nfse;
use App\Models\NfseContrato;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nfse\LeiauteDps;
use App\Services\Nfse\RespostaSefin as RespostaSefinAlias;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeraCertificados;
use Tests\Concerns\SefinFalsa;
use Tests\TestCase;

/** NFS-e Nacional: cadastro de serviços, emissão avulsa e em lote, retenções, cancelamento e DANFSe. */
class NfseTest extends TestCase
{
    use GeraCertificados, RefreshDatabase, SefinFalsa;

    protected User $user;

    protected Empresa $empresa;

    protected Destinatario $cliente;

    protected Servico $servico;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Empresa só de serviços: sem inscrição estadual
        $this->empresa = Empresa::factory()->create([
            'cnpj' => '11222333000181', 'razao_social' => 'SERVICOS TESTE LTDA', 'inscricao_estadual' => null,
            'crt' => Empresa::CRT_SIMPLES, 'logradouro' => 'Av Jeronimo Monteiro', 'numero' => '100', 'bairro' => 'Centro',
            'codigo_municipio' => '3205309', 'municipio' => 'Vitoria', 'uf' => 'ES', 'cep' => '29010000',
            'email' => 'fiscal@servicos.com.br', 'nfse_aliquota_simples' => 6.00, 'nfse_aliquota_iss' => 2.01,
        ]);
        $this->user = User::factory()->create(['empresa_id' => $this->empresa->id]);

        $this->actingAs($this->user)->post('/empresa/certificado', [
            'arquivo' => UploadedFile::fake()->createWithContent('cert.pfx', $this->gerarPfx('11222333000181')),
            'senha' => 'senha-cert',
        ])->assertSessionHasNoErrors();

        // Data fixa (depois de enviar o certificado, que nasce "agora"): competência 11/2026, produção ainda no leiaute anterior
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00', 'America/Sao_Paulo'));

        CreditoMovimento::create([
            'carteira_type' => 'empresa', 'carteira_id' => $this->empresa->id,
            'tipo' => CreditoMovimento::CORTESIA, 'quantidade' => 10,
        ]);

        $this->cliente = $this->empresa->destinatarios()->create([
            'tipo_pessoa' => 'J', 'cpf_cnpj' => '11444777000161', 'razao_social' => 'Cliente Mensal LTDA',
            'ind_ie_dest' => 9, 'email' => 'financeiro@cliente.com.br',
            'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro',
            'codigo_municipio' => '3205309', 'municipio' => 'Vitoria', 'uf' => 'ES', 'cep' => '29010000',
        ]);

        $this->servico = $this->empresa->servicos()->create([
            'codigo' => '1', 'nome' => 'Suporte em informática', 'ctribnac' => '010701', 'ctribmun' => '001',
            'cnbs' => '115013000', 'discriminacao' => 'Suporte técnico mensal referente a {mes}', 'valor' => 1500,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function emitir(array $extra = []): \Illuminate\Testing\TestResponse
    {
        $this->user->unsetRelation('empresa'); // nos testes o mesmo usuário atravessa as requisições

        return $this->actingAs($this->user)->post('/empresa/nfse', array_merge([
            'servico_id' => $this->servico->id,
            'destinatario_id' => $this->cliente->id,
            'competencia' => '2026-11',
            'valor_servico' => '1.500,00',
            'discriminacao' => 'Suporte técnico mensal referente a {mes}',
        ], $extra));
    }

    private function saldo(): int
    {
        return $this->empresa->fresh()->saldoCreditos();
    }

    public function test_cadastro_de_servico_confere_o_codigo_da_lista_nacional(): void
    {
        $this->actingAs($this->user)->get('/empresa/servicos/codigos?q=informatica')
            ->assertOk()->assertJsonFragment(['codigo' => '010701', 'codigo_formatado' => '01.07.01']);
        $this->actingAs($this->user)->get('/empresa/servicos/codigos/170101')->assertOk();
        $this->actingAs($this->user)->get('/empresa/servicos/codigos/999999')->assertNotFound();

        $this->actingAs($this->user)->post('/empresa/servicos', [
            'nome' => 'Contabilidade', 'ctribnac' => '17.19.01', 'ctribmun' => '', 'discriminacao' => 'Honorários de {mes}', 'valor' => '800,00',
        ])->assertRedirect('/empresa/servicos')->assertSessionHasNoErrors();
        $novo = Servico::where('nome', 'Contabilidade')->first();
        $this->assertSame('171901', $novo->ctribnac);
        $this->assertSame('2', $novo->codigo);
        $this->assertEquals(800, $novo->valor);

        $this->actingAs($this->user)->post('/empresa/servicos', [
            'nome' => 'Inventado', 'ctribnac' => '99.88.77', 'discriminacao' => 'x',
        ])->assertSessionHasErrors('ctribnac');
    }

    public function test_emite_nfse_autorizada_com_dps_valida_no_leiaute_da_nt009(): void
    {
        $sefin = $this->usarSefinFalsa('autorizada');

        $resposta = $this->emitir();
        $nfse = Nfse::firstOrFail();
        $resposta->assertRedirect("/empresa/nfse/{$nfse->id}");

        $this->assertSame(Nfse::AUTORIZADA, $nfse->status);
        $this->assertSame('147', $nfse->numero_nfse);
        $this->assertSame(50, strlen($nfse->chave));
        $this->assertSame('Suporte técnico mensal referente a 11/2026', $nfse->discriminacao);
        $this->assertSame(9, $this->saldo());
        Storage::disk('local')->assertExists($nfse->xml_path);
        Storage::disk('local')->assertExists($nfse->pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($nfse->pdf_path));

        // A DPS: série/número próprios, prestador só com CNPJ e regime, ME/EPP com % do Simples
        $dps = $sefin->dpsEnviadas[0];
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $dps);
        $this->assertStringContainsString('Id="DPS3205309211222333000181000500000000000000', $dps);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $dps);
        $this->assertStringContainsString('<dCompet>2026-11-20</dCompet>', $dps);
        $this->assertStringContainsString('<finNFSe>0</finNFSe>', $dps);
        $this->assertStringContainsString('<prest><CNPJ>11222333000181</CNPJ><regTrib><opSimpNac>3</opSimpNac><regApTribSN>1</regApTribSN><regEspTrib>0</regEspTrib></regTrib></prest>', $dps);
        $this->assertStringContainsString('<cTribNac>010701</cTribNac><cTribMun>001</cTribMun><cNBS>115013000</cNBS><xDescServ>', $dps);
        $this->assertStringContainsString('<tpRetISSQN>1</tpRetISSQN></tribMun><totTrib><pTotTribSN>6.00</pTotTribSN></totTrib>', $dps);
        $this->assertStringNotContainsString('<pAliq>', $dps);
        $this->assertStringNotContainsString('<tribFed>', $dps);
        $this->assertStringContainsString('<toma><CNPJ>11444777000161</CNPJ><xNome>Cliente Mensal LTDA</xNome><end><endNac><cMun>3205309</cMun><CEP>29010000</CEP></endNac>', $dps);

        // A próxima DPS continua a numeração
        $this->assertSame(2, (int) $this->empresa->fresh()->nfse_proximo_numero);

        $this->actingAs($this->user)->get("/empresa/nfse/{$nfse->id}")->assertOk()->assertSee('NFS-e nº 147')->assertSee('Emitir outra igual');
        $this->actingAs($this->user)->get("/empresa/nfse/{$nfse->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->user)->get("/empresa/nfse/{$nfse->id}/xml")->assertOk();
    }

    public function test_envia_xml_e_pdf_por_email_ao_autorizar_e_permite_reenviar(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->usarSefinFalsa('autorizada');

        $this->emitir();
        $nfse = Nfse::firstOrFail();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\NfseAutorizadaMail::class, function ($mail) use ($nfse) {

            return $mail->hasTo('financeiro@cliente.com.br') && $mail->hasTo('fiscal@servicos.com.br')
                && $mail->hasReplyTo('fiscal@servicos.com.br')
                && count($mail->attachments()) === 2
                && $mail->nfse->is($nfse);
        });
        $this->assertNotNull($nfse->fresh()->email_enviado_em);

        $this->actingAs($this->user)->post("/empresa/nfse/{$nfse->id}/email", ['emails' => 'outro@x.com.br; errado'])
            ->assertSessionHasErrors('emails');
        $this->actingAs($this->user)->post("/empresa/nfse/{$nfse->id}/email", ['emails' => 'outro@x.com.br, contas@x.com.br'])
            ->assertSessionHas('sucesso');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\NfseAutorizadaMail::class, fn ($m) => $m->hasTo('contas@x.com.br'));
        $this->actingAs($this->user)->get("/empresa/nfse/{$nfse->id}")->assertSee('E-mail enviado em');
        $this->assertStringContainsString('(NFS-e) nº <strong>147</strong>', (new \App\Mail\NfseAutorizadaMail($nfse->fresh()))->render());
    }

    public function test_producao_usa_o_leiaute_anterior_ate_30_de_novembro_e_o_novo_depois(): void
    {
        $this->empresa->update(['ambiente' => Empresa::AMBIENTE_PRODUCAO]);
        $this->assertSame(LeiauteDps::ANTERIOR, LeiauteDps::para(Empresa::AMBIENTE_PRODUCAO));

        $sefin = $this->usarSefinFalsa('autorizada', 'autorizada');
        $this->emitir()->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('<finNFSe>', $sefin->dpsEnviadas[0]);
        $this->assertStringContainsString('<cTribMun>001</cTribMun><xDescServ>', $sefin->dpsEnviadas[0]);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $sefin->dpsEnviadas[0]);

        Carbon::setTestNow(Carbon::parse('2026-12-01 08:00:00', 'America/Sao_Paulo'));
        $this->assertSame(LeiauteDps::NT009, LeiauteDps::para(Empresa::AMBIENTE_PRODUCAO));
        $this->emitir()->assertSessionHasNoErrors();
        $this->assertStringContainsString('<finNFSe>0</finNFSe>', $sefin->dpsEnviadas[1]);
        $this->assertSame(2, Nfse::where('status', Nfse::AUTORIZADA)->count());
    }

    public function test_mei_nao_informa_tributos_nem_regime_de_apuracao(): void
    {
        $this->empresa->update(['crt' => Empresa::CRT_MEI, 'nfse_aliquota_simples' => null]);
        $sefin = $this->usarSefinFalsa('autorizada');

        $this->emitir(['ret_inss' => '165,00'])->assertSessionHasNoErrors();

        $dps = $sefin->dpsEnviadas[0];
        $this->assertStringContainsString('<regTrib><opSimpNac>2</opSimpNac><regEspTrib>0</regEspTrib></regTrib>', $dps);
        $this->assertStringContainsString('<totTrib><indTotTrib>0</indTotTrib></totTrib>', $dps);
        $this->assertStringNotContainsString('<tribFed>', $dps);
        $this->assertEquals(0, Nfse::first()->ret_inss);
    }

    public function test_iss_retido_e_retencoes_federais(): void
    {
        $sefin = $this->usarSefinFalsa('autorizada');

        $this->emitir([
            'valor_servico' => '1.000,00', 'iss_retido' => '1',
            'ret_inss' => '110,00', 'ret_irrf' => '15,00', 'ret_csll' => '10,00', 'ret_pis' => '6,50', 'ret_cofins' => '30,00',
        ])->assertSessionHasNoErrors();

        $nfse = Nfse::firstOrFail();
        $this->assertSame(Nfse::AUTORIZADA, $nfse->status);
        $this->assertEquals(20.10, $nfse->valor_iss_retido);
        $this->assertEquals(1000 - 20.10 - 110 - 15 - 10 - 6.50 - 30, (float) $nfse->valor_liquido);

        $dps = $sefin->dpsEnviadas[0];
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN><pAliq>2.01</pAliq>', $dps);
        $this->assertStringContainsString('<piscofins><CST>01</CST><vBCPisCofins>1000.00</vBCPisCofins><pAliqPis>0.65</pAliqPis><pAliqCofins>3.00</pAliqCofins><vPis>6.50</vPis><vCofins>30.00</vCofins><tpRetPisCofins>3</tpRetPisCofins></piscofins>', $dps);
        $this->assertStringContainsString('<vRetCP>110.00</vRetCP><vRetIRRF>15.00</vRetIRRF><vRetCSLL>10.00</vRetCSLL>', $dps);
    }

    public function test_sem_tomador_identificado_vai_sem_o_grupo_toma(): void
    {
        $sefin = $this->usarSefinFalsa('autorizada');

        $this->emitir(['destinatario_id' => '0'])->assertSessionHasNoErrors();

        $this->assertNull(Nfse::first()->destinatario_id);
        $this->assertStringNotContainsString('<toma>', $sefin->dpsEnviadas[0]);
        $this->emitir(['destinatario_id' => '0', 'iss_retido' => '1'])->assertSessionHasErrors('emissao');
    }

    public function test_iss_retido_exige_endereco_completo_do_tomador(): void
    {
        $sefin = $this->usarSefinFalsa('autorizada');
        $this->cliente->update(['cep' => null]);

        $this->emitir(['iss_retido' => '1'])->assertSessionHasErrors('emissao');
        $this->assertSame([], $sefin->chamadas);
        $this->assertSame(0, Nfse::count());
        $this->assertSame(10, $this->saldo());
    }

    public function test_rejeicao_devolve_o_credito_e_explica_o_que_fazer(): void
    {
        $this->usarSefinFalsa('rejeitada');

        $this->emitir();
        $nfse = Nfse::firstOrFail();

        $this->assertSame(Nfse::REJEITADA, $nfse->status);
        $this->assertSame('E0312', $nfse->codigo_status);
        $this->assertSame(10, $this->saldo());
        $this->actingAs($this->user)->get("/empresa/nfse/{$nfse->id}")
            ->assertOk()->assertSee('código municipal')->assertSee('Abrir o serviço')->assertSee('Corrigir e enviar de novo');

        // Corrige e envia de novo: mesma DPS (mesmo número), agora autorizada
        $this->usarSefinFalsa('autorizada');
        $this->actingAs($this->user)->put("/empresa/nfse/{$nfse->id}", [
            'servico_id' => $this->servico->id, 'destinatario_id' => $this->cliente->id, 'competencia' => '2026-11',
            'valor_servico' => '1.500,00', 'discriminacao' => 'Suporte',
        ])->assertRedirect("/empresa/nfse/{$nfse->id}");
        $this->assertSame(Nfse::AUTORIZADA, $nfse->fresh()->status);
        $this->assertSame(1, (int) $nfse->fresh()->numero_dps);
        $this->assertSame(9, $this->saldo());
    }

    public function test_rejeitada_em_homologacao_e_reenviada_em_producao_ganha_dps_nova(): void
    {
        $sefin = $this->usarSefinFalsa('rejeitada', 'autorizada');
        $this->emitir();
        $nfse = Nfse::firstOrFail();
        $this->assertSame(2, $nfse->ambiente);

        $this->empresa->update(['ambiente' => Empresa::AMBIENTE_PRODUCAO, 'nfse_proximo_numero' => 7]);
        $this->user->unsetRelation('empresa');
        $this->actingAs($this->user)->put("/empresa/nfse/{$nfse->id}", [
            'servico_id' => $this->servico->id, 'destinatario_id' => $this->cliente->id, 'competencia' => '2026-11',
            'valor_servico' => '1.500,00', 'discriminacao' => 'Suporte',
        ]);

        $nfse->refresh();
        $this->assertSame(Nfse::AUTORIZADA, $nfse->status);
        $this->assertSame(1, $nfse->ambiente);
        $this->assertSame(7, (int) $nfse->numero_dps);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $sefin->dpsEnviadas[1]);
    }

    public function test_sem_resposta_fica_aguardando_e_a_consulta_resolve(): void
    {
        $sefin = $this->usarSefinFalsa('incerta');

        $this->emitir();
        $nfse = Nfse::firstOrFail();
        $this->assertSame(Nfse::PROCESSANDO, $nfse->status);
        $this->assertSame(9, $this->saldo()); // crédito segue reservado

        // A SEFIN não recebeu: libera e devolve o crédito
        $this->actingAs($this->user)->post("/empresa/nfse/{$nfse->id}/consultar");
        $this->assertSame(Nfse::REJEITADA, $nfse->fresh()->status);
        $this->assertSame(10, $this->saldo());
    }

    public function test_dps_que_ja_virou_nfse_e_aproveitada(): void
    {
        $sefin = $this->usarSefinFalsa('duplicada');

        $this->emitir();
        $nfse = Nfse::firstOrFail();

        $this->assertSame(Nfse::AUTORIZADA, $nfse->status);
        $this->assertContains('dps', $sefin->chamadas);
        $this->assertContains('consultar', $sefin->chamadas);
        $this->assertSame(9, $this->saldo());
    }

    public function test_cancelamento(): void
    {
        $sefin = $this->usarSefinFalsa('autorizada', 'nao_aceita', 'cancelado');
        $this->emitir();
        $nfse = Nfse::firstOrFail();

        $this->actingAs($this->user)->post("/empresa/nfse/{$nfse->id}/cancelar", ['motivo' => '1', 'justificativa' => 'curta'])
            ->assertSessionHasErrors('justificativa');

        $this->actingAs($this->user)->post("/empresa/nfse/{$nfse->id}/cancelar", ['motivo' => '1', 'justificativa' => 'Valor informado errado na nota'])
            ->assertSessionHas('erro');
        $this->assertSame(Nfse::AUTORIZADA, $nfse->fresh()->status);

        $this->actingAs($this->user)->post("/empresa/nfse/{$nfse->id}/cancelar", ['motivo' => '1', 'justificativa' => 'Valor informado errado na nota'])
            ->assertSessionHas('sucesso');
        $nfse->refresh();
        $this->assertSame(Nfse::CANCELADA, $nfse->status);
        $this->assertStringContainsString('<xDesc>Cancelamento de NFS-e</xDesc><cMotivo>1</cMotivo>', $sefin->eventosEnviados[1]);
        $this->assertStringContainsString('Id="PRE'.$nfse->chave.'101101"', $sefin->eventosEnviados[1]);
        Storage::disk('local')->assertExists($nfse->cancelamento_xml_path);
        $this->assertSame(9, $this->saldo()); // cancelamento não devolve crédito
    }

    public function test_validacoes_antes_de_enviar(): void
    {
        $sefin = $this->usarSefinFalsa();

        $this->emitir(['competencia' => '2026-12'])->assertSessionHasErrors('emissao');
        $this->emitir(['destinatario_id' => ''])->assertSessionHasErrors('destinatario_id');

        $obra = $this->empresa->servicos()->create(['codigo' => '9', 'nome' => 'Obra', 'ctribnac' => '070201', 'discriminacao' => 'Obra']);
        $this->emitir(['servico_id' => $obra->id])->assertSessionHasErrors('emissao');

        $this->empresa->update(['nfse_aliquota_simples' => null]);
        $this->emitir()->assertSessionHasErrors('emissao');

        $this->assertSame([], $sefin->chamadas);
        $this->assertSame(0, Nfse::count());
    }

    public function test_emissao_em_lote_nao_repete_cliente_no_mesmo_mes(): void
    {
        $sefin = $this->usarSefinFalsa('autorizada', 'autorizada', 'autorizada');
        $outro = $this->empresa->destinatarios()->create([
            'tipo_pessoa' => 'F', 'cpf_cnpj' => '52998224725', 'razao_social' => 'Maria da Silva', 'ind_ie_dest' => 9,
        ]);
        $a = NfseContrato::create(['empresa_id' => $this->empresa->id, 'destinatario_id' => $this->cliente->id, 'servico_id' => $this->servico->id,
            'valor' => 1500, 'retencoes' => ['ret_inss' => 11]]);
        $b = NfseContrato::create(['empresa_id' => $this->empresa->id, 'destinatario_id' => $outro->id, 'servico_id' => $this->servico->id,
            'valor' => 300, 'discriminacao' => 'Mensalidade de {mes}']);
        $pausado = NfseContrato::create(['empresa_id' => $this->empresa->id, 'destinatario_id' => $outro->id, 'servico_id' => $this->servico->id,
            'valor' => 50, 'ativo' => false]);

        $this->actingAs($this->user)->get('/empresa/nfse/lote?competencia=2026-11')
            ->assertOk()->assertSee('Cliente Mensal LTDA')->assertSee('Maria da Silva')->assertDontSee('50,00');

        $this->user->unsetRelation('empresa');
        $this->actingAs($this->user)->post('/empresa/nfse/lote', [
            'competencia' => '2026-11',
            'itens' => [$a->id => ['marcado' => '1', 'valor' => '1.500,00'], $b->id => ['marcado' => '1', 'valor' => '350,00']],
        ])->assertRedirect();

        $this->assertSame(2, Nfse::where('status', Nfse::AUTORIZADA)->count());
        $notaA = Nfse::where('contrato_id', $a->id)->first();
        $notaB = Nfse::where('contrato_id', $b->id)->first();
        $this->assertEquals(165, $notaA->ret_inss);                 // 11% de 1.500
        $this->assertEquals(350, $notaB->valor_servico);            // valor alterado só neste mês
        $this->assertSame('Mensalidade de 11/2026', $notaB->discriminacao);
        $this->assertEquals(300, $b->fresh()->valor);
        $this->assertNotNull($notaA->lote);
        $this->assertSame($notaA->lote, $notaB->lote);
        $this->assertStringNotContainsString('<toma><CPF>52998224725</CPF><xNome>Maria da Silva</xNome><end>', $sefin->dpsEnviadas[1]); // sem endereço: vai sem <end>

        // De novo no mesmo mês: nada é emitido em dobro
        $this->actingAs($this->user)->post('/empresa/nfse/lote', [
            'competencia' => '2026-11', 'itens' => [$a->id => ['marcado' => '1'], $b->id => ['marcado' => '1']],
        ]);
        $this->assertSame(2, Nfse::count());
        $this->assertSame(8, $this->saldo());

        $this->actingAs($this->user)->get('/empresa/nfse?lote='.$notaA->lote)->assertOk()->assertSee('Resultado da emissão em lote');
    }

    public function test_reajuste_anual_e_desfazer(): void
    {
        $a = NfseContrato::create(['empresa_id' => $this->empresa->id, 'destinatario_id' => $this->cliente->id, 'servico_id' => $this->servico->id, 'valor' => 1000]);
        $b = NfseContrato::create(['empresa_id' => $this->empresa->id, 'destinatario_id' => $this->cliente->id, 'servico_id' => $this->servico->id, 'valor' => 333.33]);

        $this->actingAs($this->user)->post('/empresa/nfse/lote/reajuste', ['percentual' => '5,5', 'contratos' => [$a->id]])->assertSessionHas('sucesso');
        $this->assertEquals(1055, $a->fresh()->valor);
        $this->assertEquals(1000, $a->fresh()->valor_anterior);
        $this->assertEquals(333.33, $b->fresh()->valor);

        $this->actingAs($this->user)->post('/empresa/nfse/lote/desfazer-reajuste')->assertSessionHas('sucesso');
        $this->assertEquals(1000, $a->fresh()->valor);
        $this->assertNull($a->fresh()->valor_anterior);
    }

    public function test_clientes_mensais_crud(): void
    {
        $this->actingAs($this->user)->post('/empresa/nfse/clientes', [
            'destinatario_id' => $this->cliente->id, 'servico_id' => $this->servico->id, 'valor' => '2.000,00',
            'pct_ret_inss' => '11', 'iss_retido' => '1', 'ativo' => '1',
        ])->assertRedirect('/empresa/nfse/clientes')->assertSessionHasNoErrors();

        $c = NfseContrato::firstOrFail();
        $this->assertEquals(2000, $c->valor);
        $this->assertSame(['ret_inss' => 11], array_map('intval', $c->retencoes));
        $this->assertTrue($c->iss_retido);

        $this->actingAs($this->user)->get('/empresa/nfse/clientes')->assertOk()->assertSee('Cliente Mensal LTDA');
        $this->actingAs($this->user)->get("/empresa/nfse/clientes/{$c->id}/edit")->assertOk();
    }

    public function test_telas_e_menu_de_servicos(): void
    {
        $this->actingAs($this->user)->get('/empresa/nfse/emitir')->assertOk()->assertSee('Emitir NFS-e')->assertSee('Emissão em lote')->assertSee('Cadastro de serviços');
        $this->actingAs($this->user)->get('/empresa/nfse/emitir?servico='.$this->servico->id)->assertOk()->assertSee('Suporte técnico mensal');
        $this->actingAs($this->user)->get('/empresa/nfse')->assertOk();
        $this->actingAs($this->user)->get('/empresa/servicos')->assertOk()->assertSee('Suporte em informática');
        $this->actingAs($this->user)->get('/empresa/servicos/create')->assertOk();
        $this->actingAs($this->user)->get("/empresa/servicos/{$this->servico->id}/edit")->assertOk()->assertSee('01.07.01');
        $this->actingAs($this->user)->get('/empresa/nfse/lote')->assertOk()->assertSee('Nenhum cliente mensal');
        $this->actingAs($this->user)->get('/empresa')->assertOk()->assertSee('Serviços');
        $this->actingAs($this->user)->get('/empresa/dados')->assertOk()->assertSee('NFS-e (notas de serviço)');
    }

    public function test_dados_da_empresa_guardam_a_configuracao_da_nfse(): void
    {
        $this->actingAs($this->user)->put('/empresa/dados', [
            'razao_social' => 'SERVICOS TESTE LTDA', 'inscricao_estadual' => '', 'crt' => Empresa::CRT_SIMPLES,
            'cep' => '29010-000', 'logradouro' => 'Av Jeronimo Monteiro', 'numero' => '100', 'bairro' => 'Centro',
            'municipio' => 'Vitoria', 'uf' => 'ES', 'codigo_municipio' => '3205309', 'email' => 'fiscal@servicos.com.br',
            'serie_nfe' => 1, 'proximo_numero_nfe' => 1,
            'inscricao_municipal' => '12.345', 'nfse_serie' => '70', 'nfse_ultimo_numero' => '12',
            'nfse_regime_apuracao' => '1', 'nfse_aliquota_simples' => '6,72', 'nfse_aliquota_iss' => '2,5',
        ])->assertRedirect('/empresa/dados')->assertSessionHasNoErrors();

        $e = $this->empresa->fresh();
        $this->assertNull($e->inscricao_estadual);
        $this->assertSame('12345', $e->inscricao_municipal);
        $this->assertSame('70', $e->nfse_serie);
        $this->assertSame(13, $e->nfse_proximo_numero);
        $this->assertEquals(6.72, $e->nfse_aliquota_simples);
        $this->assertEquals(2.5, $e->nfse_aliquota_iss);
    }

    public function test_conexao_real_usa_tls12_http11_e_explica_o_403_do_iis(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'sefin.producaorestrita.nfse.gov.br/*' => \Illuminate\Support\Facades\Http::response(
                '<html><body><h2>403 - Forbidden: Access is denied.</h2></body></html>', 403),
        ]);

        $resposta = (new \App\Services\Nfse\ClienteSefinHttp($this->empresa->fresh()))->emitir('<DPS/>');

        $this->assertSame(RespostaSefinAlias::RECUSADA, $resposta->situacao);
        $this->assertStringContainsString('nfse:conexao', $resposta->mensagem);
        $this->assertStringContainsString('Access is denied', $resposta->mensagem);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request->url() === 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional/nfse'
                && str_starts_with($request->header('User-Agent')[0] ?? '', 'NotaPix');
        });
    }

    public function test_comando_de_teste_de_conexao(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'sefin.producaorestrita.nfse.gov.br/*' => \Illuminate\Support\Facades\Http::response(['erros' => [['codigo' => 'E404', 'descricao' => 'Não encontrado']]], 404),
        ]);

        $this->artisan('nfse:conexao', ['cnpj' => '11.222.333/0001-81'])
            ->expectsOutputToContain('aceitou o certificado')
            ->assertSuccessful();
    }

    public function test_comando_de_teste_de_conexao_mostra_a_recusa(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('403 - Forbidden: Access is denied.', 403)]);

        $this->artisan('nfse:conexao', ['cnpj' => '11222333000181'])
            ->expectsOutputToContain('Access is denied')
            ->assertFailed();
    }

    public function test_nfe_exige_inscricao_estadual(): void
    {
        $problemas = app(\App\Services\Nfe\ValidadorEmissao::class)->problemas(
            $this->empresa->notas()->create(['modelo' => 55, 'status' => 'rascunho', 'natureza_operacao' => 'Venda', 'ambiente' => 2])->load('empresa')
        );

        $this->assertTrue(collect($problemas)->contains(fn ($p) => str_contains($p, 'inscrição estadual')));
    }
}
