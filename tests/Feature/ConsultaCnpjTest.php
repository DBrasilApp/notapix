<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GeraCertificados;
use Tests\Concerns\SefazFalsa;
use Tests\TestCase;

class ConsultaCnpjTest extends TestCase
{
    use GeraCertificados, RefreshDatabase, SefazFalsa;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('local');

        $empresa = Empresa::factory()->create(['cnpj' => '11222333000181', 'uf' => 'ES']);
        $this->user = User::factory()->create(['empresa_id' => $empresa->id]);

        Http::fake(['brasilapi.com.br/api/cnpj/v1/11444777000161' => Http::response([
            'razao_social' => 'CLIENTE EXEMPLO LTDA', 'nome_fantasia' => 'EXEMPLO',
            'descricao_tipo_de_logradouro' => 'AVENIDA', 'logradouro' => 'JERONIMO MONTEIRO', 'numero' => '100',
            'complemento' => 'SALA  2', 'bairro' => 'CENTRO', 'municipio' => 'VITORIA', 'uf' => 'ES',
            'cep' => '29010000', 'codigo_municipio_ibge' => 3205309, 'email' => 'Compras@Cliente.com.br',
            'ddd_telefone_1' => '2733334444', 'descricao_situacao_cadastral' => 'ATIVA', 'opcao_pelo_mei' => false,
        ]),
            'brasilapi.com.br/api/cnpj/v1/45723174000110' => Http::response(['message' => 'não encontrado'], 404),
            'publica.cnpj.ws/cnpj/45723174000110' => Http::response(['status' => 404], 404),
            'receitaws.com.br/v1/cnpj/45723174000110' => Http::response(['status' => 'ERROR', 'message' => 'CNPJ inválido']),
        ]);
    }

    public function test_preenche_com_dados_da_receita_sem_certificado(): void
    {
        $this->actingAs($this->user)->getJson('/empresa/cnpj/11444777000161')
            ->assertOk()
            ->assertJson([
                'razao_social' => 'CLIENTE EXEMPLO LTDA', 'logradouro' => 'AVENIDA JERONIMO MONTEIRO',
                'complemento' => 'SALA 2', 'codigo_municipio' => '3205309', 'email' => 'compras@cliente.com.br',
                'telefone' => '2733334444', 'ativa' => true, 'ie' => null, 'ind_ie_dest' => null,
            ]);
    }

    public function test_busca_inscricao_estadual_na_sefaz_com_certificado(): void
    {
        $this->enviarCertificado();
        $fabrica = $this->usarSefazFalsa();
        $fabrica->cadastro = '<retConsCad xmlns="http://www.portalfiscal.inf.br/nfe" versao="2.00"><infCons>'
            .'<cStat>111</cStat><xMotivo>Consulta cadastro com uma ocorrencia</xMotivo>'
            .'<infCad><IE>82560668</IE><CNPJ>11444777000161</CNPJ><UF>ES</UF><cSit>1</cSit><indCredNFe>4</indCredNFe>'
            .'<xNome>CLIENTE EXEMPLO LTDA</xNome></infCad></infCons></retConsCad>';

        $this->actingAs($this->user)->getJson('/empresa/cnpj/11444777000161')
            ->assertOk()->assertJson(['ie' => '082560668', 'ind_ie_dest' => 1]);
    }

    public function test_cnpj_sem_inscricao_vira_nao_contribuinte(): void
    {
        $this->enviarCertificado();
        $this->usarSefazFalsa(); // resposta padrão: 259

        $this->actingAs($this->user)->getJson('/empresa/cnpj/11444777000161')
            ->assertOk()->assertJson(['ie' => null, 'ind_ie_dest' => 9]);
    }

    public function test_cnpj_inexistente_e_invalido(): void
    {
        $this->actingAs($this->user)->getJson('/empresa/cnpj/11222333000199')->assertStatus(422);
        $this->actingAs($this->user)->getJson('/empresa/cnpj/45723174000110')->assertNotFound();
    }

    private function enviarCertificado(): void
    {
        $this->actingAs($this->user)->post('/empresa/certificado', [
            'arquivo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('cert.pfx', $this->gerarPfx('11222333000181')),
            'senha' => 'senha-cert',
        ])->assertSessionHasNoErrors();
    }

    public function test_cadastro_de_nova_conta_usa_o_certificado_da_empresa_de_consulta(): void
    {
        $this->enviarCertificado();          // empresa 11222333000181 com certificado
        config(['nfeexpress.cnpj_consulta' => '11.222.333/0001-81']);
        $fabrica = $this->usarSefazFalsa();
        $fabrica->cadastro = '<retConsCad xmlns="http://www.portalfiscal.inf.br/nfe" versao="2.00"><infCons>'
            .'<cStat>111</cStat><xMotivo>ok</xMotivo><infCad><IE>82513333</IE><CNPJ>11444777000161</CNPJ>'
            .'<UF>ES</UF><cSit>1</cSit></infCad></infCons></retConsCad>';

        auth()->logout();
        $this->getJson('/cadastro/cnpj/11444777000161')->assertOk()
            ->assertJson(['ie' => '082513333', 'razao_social' => 'CLIENTE EXEMPLO LTDA']);
    }

    public function test_sem_empresa_de_consulta_o_cadastro_vem_sem_ie(): void
    {
        $this->getJson('/cadastro/cnpj/11444777000161')->assertOk()->assertJson(['ie' => null]);
    }

    public function test_quando_a_brasilapi_nao_encontra_usa_a_cnpj_ws(): void
    {
        Http::fake([
            'brasilapi.com.br/*' => Http::response(['message' => 'CNPJ não encontrado'], 404),
            'publica.cnpj.ws/cnpj/06698240000198' => Http::response([
                'razao_social' => 'LOJA NOVA LTDA',
                'simples' => ['simples' => 'Sim', 'mei' => 'Não'],
                'estabelecimento' => [
                    'nome_fantasia' => 'LOJA NOVA', 'situacao_cadastral' => 'Ativa', 'tipo_logradouro' => 'Rua',
                    'logradouro' => 'SETE DE SETEMBRO', 'numero' => '50', 'bairro' => 'CENTRO', 'cep' => '29010000',
                    'ddd1' => '27', 'telefone1' => '33334444', 'email' => 'contato@lojanova.com.br',
                    'cidade' => ['nome' => 'Vitória', 'ibge_id' => 3205309], 'estado' => ['sigla' => 'ES'],
                    'atividade_principal' => ['subclasse' => '4781400'],
                    'inscricoes_estaduais' => [['inscricao_estadual' => '83211111', 'ativo' => true, 'estado' => ['sigla' => 'ES']]],
                ],
            ]),
        ]);

        // Cadastro de nova conta (sem certificado): a IE vem do cadastro público, com o zero
        auth()->logout();
        $this->getJson('/cadastro/cnpj/06698240000198')->assertOk()->assertJson([
            'razao_social' => 'LOJA NOVA LTDA', 'logradouro' => 'Rua SETE DE SETEMBRO', 'codigo_municipio' => '3205309',
            'telefone' => '2733334444', 'crt' => Empresa::CRT_SIMPLES, 'ativa' => true, 'ie' => '083211111',
        ]);
    }

    public function test_usa_a_receitaws_como_ultima_opcao(): void
    {
        Http::fake([
            'brasilapi.com.br/*' => Http::response([], 500),
            'publica.cnpj.ws/*' => Http::response([], 429),
            'receitaws.com.br/v1/cnpj/06698240000198' => Http::response([
                'status' => 'OK', 'nome' => 'LOJA NOVA LTDA', 'fantasia' => '', 'logradouro' => 'RUA SETE', 'numero' => '50',
                'bairro' => 'CENTRO', 'municipio' => 'VITORIA', 'uf' => 'ES', 'cep' => '29.010-000', 'telefone' => '(27) 3333-4444',
                'situacao' => 'ATIVA', 'simples' => ['optante' => true], 'simei' => ['optante' => true],
            ]),
        ]);

        $this->actingAs($this->user)->getJson('/empresa/cnpj/06698240000198')->assertOk()
            ->assertJson(['razao_social' => 'LOJA NOVA LTDA', 'cep' => '29010000', 'crt' => Empresa::CRT_MEI]);
    }

    public function test_todos_fora_do_ar_e_erro_de_servico(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAs($this->user)->getJson('/empresa/cnpj/06698240000198')->assertStatus(503);
    }
}
