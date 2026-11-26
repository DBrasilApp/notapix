<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\CreditoMovimento;
use App\Models\Empresa;
use App\Models\Escritorio;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GeraCertificados;
use Tests\Concerns\SefazFalsa;
use Tests\TestCase;

/** Escritório contábil: clientes, acesso às empresas, créditos e XML do mês. */
class EscritorioTest extends TestCase
{
    use GeraCertificados, RefreshDatabase, SefazFalsa;

    private User $contador;

    private Escritorio $escritorio;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();

        $this->contador = User::factory()->escritorio()->create();
        $this->escritorio = $this->contador->escritorio;
    }

    private function cliente(array $pivot = []): Empresa
    {
        $empresa = Empresa::factory()->create(['uf' => 'ES', 'cnpj' => '11222333000181']);
        $this->escritorio->empresas()->attach($empresa->id, $pivot + ['status' => 'ativo', 'autorizado_em' => now()]);

        return $empresa;
    }

    private function creditos(Empresa|Escritorio $carteira, int $quantidade): void
    {
        CreditoMovimento::create([
            'carteira_type' => $carteira->getMorphClass(), 'carteira_id' => $carteira->id,
            'tipo' => CreditoMovimento::CORTESIA, 'quantidade' => $quantidade,
        ]);
    }

    public function test_escritorio_cadastra_cliente_e_convida_o_responsavel(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response([], 404)]);

        $this->actingAs($this->contador)->get('/escritorio/empresas/nova')->assertOk()->assertSee('Acesso do cliente');

        $this->actingAs($this->contador)->post('/escritorio/empresas', [
            'cnpj' => '11.222.333/0001-81', 'razao_social' => 'Cliente do Contador LTDA', 'inscricao_estadual' => '082560668',
            'crt' => 1, 'cep' => '29010-000', 'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro',
            'municipio' => 'Vitória', 'uf' => 'ES', 'codigo_municipio' => '3205309', 'email' => 'fiscal@cliente.com.br',
            'ja_emitiu' => '0', 'criar_acesso' => '1', 'responsavel' => 'Maria Cliente', 'email_acesso' => 'maria@cliente.com.br',
        ])->assertRedirect('/escritorio')->assertSessionHasNoErrors();

        $empresa = Empresa::where('cnpj', '11222333000181')->firstOrFail();
        $this->assertSame('ativo', $this->escritorio->empresas()->first()->pivot->status);
        $this->assertSame(Empresa::AMBIENTE_HOMOLOGACAO, $empresa->ambiente);

        $usuario = User::where('email', 'maria@cliente.com.br')->firstOrFail();
        $this->assertSame([Perfil::Empresa, $empresa->id], [$usuario->perfil, $usuario->empresa_id]);
        Notification::assertSentTo($usuario, ResetPassword::class);
    }

    public function test_cnpj_que_ja_usa_o_notapix_orienta_a_pedir_autorizacao(): void
    {
        Empresa::factory()->create(['cnpj' => '11222333000181']);

        $this->actingAs($this->contador)->getJson('/escritorio/cnpj/11222333000181')
            ->assertStatus(409)->assertJsonFragment(['mensagem' => 'Esta empresa já usa o NotaPix. Peça para ela autorizar o seu escritório em "Contador", no menu dela.']);
    }

    public function test_acessa_a_empresa_cliente_e_trabalha_nela(): void
    {
        $empresa = $this->cliente();

        $this->actingAs($this->contador)->get('/escritorio')->assertOk()->assertSee($empresa->razao_social)->assertSee('Acessar');
        $this->actingAs($this->contador)->post("/escritorio/empresas/{$empresa->id}/acessar")->assertRedirect('/empresa');

        $this->actingAs($this->contador)->get('/empresa')->assertOk()
            ->assertSee('Você está trabalhando na empresa')->assertSee($empresa->razao_social);

        // Cadastra um produto na empresa-cliente
        $this->actingAs($this->contador)->post('/empresa/produtos', \App\Models\Produto::PADROES_SIMPLES + [
            'descricao' => 'Produto do cliente', 'ncm' => '61091000', 'unidade' => 'UN', 'valor_unitario' => '10,00', 'gtin' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $empresa->produtos()->count());

        $this->actingAs($this->contador)->post('/escritorio/sair-da-empresa')->assertRedirect('/escritorio');
        $this->actingAs($this->contador)->get('/empresa')->assertRedirect('/escritorio');
    }

    public function test_nao_acessa_empresa_que_nao_e_cliente(): void
    {
        $outra = Empresa::factory()->create();
        $pendente = Empresa::factory()->create();
        $this->escritorio->empresas()->attach($pendente->id, ['status' => 'pendente', 'solicitado_por' => 'empresa']);

        $this->actingAs($this->contador)->post("/escritorio/empresas/{$outra->id}/acessar")->assertNotFound();
        $this->actingAs($this->contador)->post("/escritorio/empresas/{$pendente->id}/acessar")->assertNotFound();

        // Nem forçando a sessão
        $this->actingAs($this->contador)->withSession([\App\Http\Middleware\EmpresaAtual::SESSAO => $outra->id])
            ->get('/empresa')->assertRedirect('/escritorio');
    }

    public function test_empresa_autoriza_o_escritorio_e_ele_aceita(): void
    {
        $dono = User::factory()->create();

        $this->actingAs($dono)->get('/empresa/contador')->assertOk()->assertSee('Autorizar escritório');
        $this->actingAs($dono)->post('/empresa/contador', ['cnpj_escritorio' => \App\Support\Formata::cnpj($this->escritorio->cnpj)])
            ->assertSessionHasNoErrors();
        $this->assertSame('pendente', $dono->empresa->escritorios()->first()->pivot->status);

        $this->actingAs($this->contador)->get('/escritorio')->assertSee('Empresas pedindo para você ser o contador delas');
        $this->actingAs($this->contador)->post("/escritorio/empresas/{$dono->empresa_id}/aceitar")->assertSessionHas('sucesso');
        $this->assertSame('ativo', $dono->empresa->escritorios()->first()->pivot->status);

        // A empresa remove o acesso
        $this->actingAs($dono)->delete('/empresa/contador');
        $this->assertSame('revogado', $dono->empresa->escritorios()->first()->pivot->status);
        $this->actingAs($this->contador)->post("/escritorio/empresas/{$dono->empresa_id}/acessar")->assertNotFound();
    }

    public function test_empresa_autoriza_contador_pelo_cpf(): void
    {
        $dono = User::factory()->create();
        $contadorCpf = Escritorio::factory()->create(['cnpj' => '52998224725', 'razao_social' => 'José Contador']);

        $this->actingAs($dono)->post('/empresa/contador', ['cnpj_escritorio' => '529.982.247-25'])->assertSessionHasNoErrors();

        $this->assertSame($contadorCpf->id, $dono->empresa->escritorios()->first()->id);
        $this->actingAs($dono)->get('/empresa/contador')->assertOk()->assertSee('CPF 529.982.247-25');
        $this->actingAs($dono)->post('/empresa/contador', ['cnpj_escritorio' => '111.222.333-44'])->assertSessionHasErrors('cnpj_escritorio');
    }

    public function test_escritorio_inexistente_e_segundo_contador_sao_recusados(): void
    {
        $dono = User::factory()->create();

        $this->actingAs($dono)->post('/empresa/contador', ['cnpj_escritorio' => '45.723.174/0001-10'])->assertSessionHasErrors('cnpj_escritorio');

        $this->escritorio->empresas()->attach($dono->empresa_id, ['status' => 'ativo']);
        $outro = Escritorio::factory()->create();
        $this->actingAs($dono)->post('/empresa/contador', ['cnpj_escritorio' => $outro->cnpj])->assertSessionHas('erro');
    }

    public function test_repassa_creditos_para_a_empresa(): void
    {
        $empresa = $this->cliente();
        $this->creditos($this->escritorio, 10);

        $this->actingAs($this->contador)->post("/escritorio/empresas/{$empresa->id}/creditos", ['quantidade' => 4])->assertSessionHas('sucesso');
        $this->assertSame([6, 4], [$this->escritorio->saldoCreditos(), $empresa->saldoCreditos()]);

        // Mais do que tem: recusado
        $this->actingAs($this->contador)->post("/escritorio/empresas/{$empresa->id}/creditos", ['quantidade' => 7])->assertSessionHas('erro');
        $this->assertSame(6, $this->escritorio->saldoCreditos());
    }

    public function test_empresa_sem_saldo_usa_o_do_escritorio_quando_permitido(): void
    {
        $empresa = $this->cliente();
        $empresa->update([
            'razao_social' => 'EMPRESA TESTE LTDA', 'inscricao_estadual' => '082560668', 'crt' => Empresa::CRT_SIMPLES,
            'logradouro' => 'Av Jeronimo Monteiro', 'numero' => '100', 'bairro' => 'Centro', 'codigo_municipio' => '3205309',
            'municipio' => 'Vitoria', 'cep' => '29010000', 'email' => 'fiscal@empresa.com.br', 'proximo_numero_nfe' => 1,
        ]);
        $this->creditos($this->escritorio, 3);

        $this->actingAs($this->contador)->post("/escritorio/empresas/{$empresa->id}/acessar");
        $this->actingAs($this->contador)->post('/empresa/certificado', [
            'arquivo' => UploadedFile::fake()->createWithContent('cert.pfx', $this->gerarPfx('11222333000181')), 'senha' => 'senha-cert',
        ])->assertSessionHasNoErrors();
        $pessoa = $empresa->destinatarios()->create([
            'tipo_pessoa' => 'J', 'cpf_cnpj' => '11444777000161', 'razao_social' => 'Cliente', 'ind_ie_dest' => 9,
            'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'codigo_municipio' => '3205309',
            'municipio' => 'Vitoria', 'uf' => 'ES', 'cep' => '29010000',
        ]);
        $produto = $empresa->produtos()->create(\App\Models\Produto::PADROES_SIMPLES + [
            'codigo' => '1', 'descricao' => 'Camiseta', 'ncm' => '61091000', 'valor_unitario' => 10,
        ]);
        $nota = fn () => [
            'operacao' => 'venda', 'destinatario_id' => $pessoa->id, 'modalidade_frete' => 9, 'forma_pagamento' => '17', 'ind_pagamento' => 0,
            'itens' => [['produto_id' => $produto->id, 'quantidade' => '1', 'valor_unitario' => '10,00']], 'acao' => 'emitir',
        ];

        // Sem permissão: a empresa sem saldo não emite
        $this->actingAs($this->contador)->post('/empresa/notas', $nota())->assertSessionHasErrors('emissao');

        // Com permissão: o crédito sai do escritório; se rejeitar, volta para o escritório
        $this->escritorio->empresas()->updateExistingPivot($empresa->id, ['usar_saldo_escritorio' => true]);
        $this->usarSefazFalsa('rejeitada', 'autorizada');
        $this->actingAs($this->contador)->post('/empresa/notas', $nota());
        $this->assertSame(3, $this->escritorio->saldoCreditos());
        $rejeitada = Nota::latest('id')->first();
        $this->assertSame(Nota::REJEITADA, $rejeitada->status);

        $this->actingAs($this->contador)->post("/empresa/notas/{$rejeitada->id}/transmitir");
        $this->assertSame(Nota::AUTORIZADA, $rejeitada->fresh()->status);
        $this->assertSame([2, 0], [$this->escritorio->saldoCreditos(), $empresa->saldoCreditos()]);
    }

    public function test_baixa_o_xml_do_mes_de_todas_as_empresas(): void
    {
        $empresa = $this->cliente();
        $chave = str_repeat('3', 44);
        Storage::disk('local')->put("notas/{$empresa->id}/x-procNFe.xml", '<nfeProc/>');
        Nota::create([
            'empresa_id' => $empresa->id, 'user_id' => $this->contador->id, 'modelo' => 55, 'serie' => 1, 'numero' => 1,
            'chave' => $chave, 'ambiente' => 2, 'status' => Nota::AUTORIZADA, 'natureza_operacao' => 'Venda',
            'autorizada_em' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3), 'xml_path' => "notas/{$empresa->id}/x-procNFe.xml",
            'destinatario_dados' => [],
        ]);

        $this->actingAs($this->contador)->get('/escritorio/arquivos')->assertOk()->assertSee('Todas as empresas');
        $resposta = $this->actingAs($this->contador)->get('/escritorio/arquivos?mes='.now()->subMonthNoOverflow()->format('Y-m'));
        $resposta->assertOk();
        $arquivo = $resposta->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($arquivo);
        $this->assertSame("autorizadas/{$chave}-procNFe.xml", $zip->getNameIndex(0));

        // Mês sem notas
        $this->actingAs($this->contador)->from('/escritorio/arquivos')->get('/escritorio/arquivos?mes=2020-01')->assertSessionHas('erro');
    }

    public function test_comando_concede_creditos_ao_escritorio(): void
    {
        $this->artisan('creditos:conceder', ['cnpj' => $this->escritorio->cnpj, 'quantidade' => 10])->assertSuccessful();
        $this->assertSame(10, $this->escritorio->saldoCreditos());

        // Empresa e escritório com o mesmo CNPJ: padrão é a empresa; --escritorio manda para o escritório
        $empresa = Empresa::factory()->create(['cnpj' => $this->escritorio->cnpj]);
        $this->artisan('creditos:conceder', ['cnpj' => $this->escritorio->cnpj, 'quantidade' => 2])->assertSuccessful();
        $this->artisan('creditos:conceder', ['cnpj' => $this->escritorio->cnpj, 'quantidade' => 3, '--escritorio' => true])->assertSuccessful();
        $this->assertSame([13, 2], [$this->escritorio->saldoCreditos(), $empresa->saldoCreditos()]);
    }

    public function test_comando_mostra_o_login_e_redefine_a_senha(): void
    {
        $dono = User::factory()->create(['email' => 'dono@costelao.com.br']);
        $cnpj = $dono->empresa->cnpj;

        $this->artisan('usuario:senha', ['busca' => $cnpj, '--senha' => 'NovaSenha123'])
            ->expectsOutputToContain('dono@costelao.com.br')->assertSuccessful();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NovaSenha123', $dono->fresh()->password));

        $this->artisan('usuario:senha', ['busca' => $this->escritorio->cnpj])->expectsOutputToContain($this->contador->email)->assertSuccessful();
        $this->artisan('usuario:senha', ['busca' => '45723174000110'])->assertFailed();
    }
}
