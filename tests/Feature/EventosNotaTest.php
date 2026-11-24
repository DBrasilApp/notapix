<?php

namespace Tests\Feature;

use App\Models\CreditoMovimento;
use App\Models\Destinatario;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\NotaEvento;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeraCertificados;
use Tests\Concerns\SefazFalsa;
use Tests\TestCase;

/** Cancelamento e carta de correção de NF-e autorizada. */
class EventosNotaTest extends TestCase
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

    private function notaAutorizada(): Nota
    {
        $this->usarSefazFalsa('autorizada');
        $this->actingAs($this->user)->post('/empresa/notas', [
            'operacao' => 'venda', 'destinatario_id' => $this->fornecedor->id, 'modalidade_frete' => 9,
            'forma_pagamento' => '17', 'ind_pagamento' => 0, 'acao' => 'emitir',
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario' => '49,90', 'cfop' => '']],
        ])->assertSessionHasNoErrors();

        $nota = Nota::latest('id')->firstOrFail();
        $this->assertSame(Nota::AUTORIZADA, $nota->status, (string) $nota->motivo);

        return $nota;
    }

    public function test_cancela_nota_autorizada(): void
    {
        $nota = $this->notaAutorizada();
        $this->usarSefazFalsa('registrado');

        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/cancelar", ['justificativa' => 'Venda desfeita, o cliente desistiu'])
            ->assertRedirect("/empresa/notas/{$nota->id}")->assertSessionHasNoErrors();

        $nota->refresh();
        $this->assertSame(Nota::CANCELADA, $nota->status);
        $evento = NotaEvento::firstOrFail();
        $this->assertSame(['cancelamento', 'registrado', '332260000099999'], [$evento->tipo, $evento->status, $evento->protocolo]);

        // XML do evento com o protocolo (procEventoNFe) e DANFE refeito
        $xml = Storage::disk('local')->get($evento->xml_path);
        $this->assertStringContainsString('<procEventoNFe', $xml);
        $this->assertStringContainsString('<xJust>Venda desfeita, o cliente desistiu</xJust>', $xml);
        $this->assertTrue(Storage::disk('local')->exists($nota->pdf_path));

        // O crédito não volta
        $this->assertSame(4, $this->empresa->saldoCreditos());

        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}")->assertOk()
            ->assertSee('Nota cancelada na SEFAZ')->assertDontSee('Cancelar nota na SEFAZ');
        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}/eventos/{$evento->id}/xml")->assertOk();
    }

    public function test_nao_cancela_depois_do_prazo_nem_sem_motivo(): void
    {
        $nota = $this->notaAutorizada();
        $fabrica = $this->usarSefazFalsa('registrado');

        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/cancelar", ['justificativa' => 'curto'])
            ->assertSessionHasErrors('justificativa');

        $nota->update(['autorizada_em' => now()->subHours(25)]);
        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/cancelar", ['justificativa' => 'Venda desfeita, o cliente desistiu'])
            ->assertSessionHasErrors('cancelamento');

        $this->assertSame([], $fabrica->chamadas); // nem chegou a chamar a SEFAZ
        $this->assertSame(Nota::AUTORIZADA, $nota->fresh()->status);
    }

    public function test_cancelamento_recusado_pela_sefaz(): void
    {
        $nota = $this->notaAutorizada();
        $this->usarSefazFalsa('prazo');

        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/cancelar", ['justificativa' => 'Venda desfeita, o cliente desistiu'])
            ->assertSessionHasErrors(['cancelamento' => 'O prazo para cancelar terminou. Depois do prazo, o cancelamento só pode ser pedido diretamente à SEFAZ (extemporâneo). Fale com o seu contador. (A SEFAZ não aceitou o cancelamento, código 501: Rejeicao: Prazo de cancelamento superior ao previsto na Legislacao)']);

        $this->assertSame(Nota::AUTORIZADA, $nota->fresh()->status);
        $this->assertSame('rejeitado', NotaEvento::first()->status);
    }

    public function test_cartas_de_correcao_em_sequencia_com_pdf(): void
    {
        $nota = $this->notaAutorizada();
        $this->usarSefazFalsa('registrado', 'registrado');

        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/correcao", ['correcao' => "No item 1, onde se le CAMISETA,\nleia-se CAMISETA BRANCA."])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/correcao", ['correcao' => 'No item 1, leia-se CAMISETA BRANCA M. Endereco: Rua A, 10.'])
            ->assertSessionHasNoErrors();

        $cartas = NotaEvento::where('tipo', 'carta_correcao')->orderBy('sequencia')->get();
        $this->assertSame([1, 2], $cartas->pluck('sequencia')->all());
        $this->assertSame('No item 1, onde se le CAMISETA, leia-se CAMISETA BRANCA.', $cartas[0]->texto); // sem quebra de linha
        $this->assertSame(Nota::AUTORIZADA, $nota->fresh()->status);

        $pdf = $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}/eventos/{$cartas[1]->id}/pdf");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_carta_recusada_nao_conta_na_sequencia(): void
    {
        $nota = $this->notaAutorizada();
        $this->usarSefazFalsa('duplicado', 'registrado');

        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/correcao", ['correcao' => 'Correcao de teste numero um'])
            ->assertSessionHasErrors('correcao');
        $this->actingAs($this->user)->post("/empresa/notas/{$nota->id}/correcao", ['correcao' => 'Correcao de teste numero dois'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, NotaEvento::where('status', 'registrado')->value('sequencia'));
    }

    public function test_nota_de_outra_empresa_nao_pode_ser_cancelada(): void
    {
        $nota = $this->notaAutorizada();
        $outro = User::factory()->create();

        $this->actingAs($outro)->post("/empresa/notas/{$nota->id}/cancelar", ['justificativa' => 'Venda desfeita, o cliente desistiu'])
            ->assertNotFound();
    }
}
