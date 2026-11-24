<?php

namespace Tests\Feature;

use App\Models\CreditoMovimento;
use App\Models\Destinatario;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\Produto;
use App\Models\User;
use App\Services\Nfe\MontadorXmlNfe;
use App\Support\ChaveAcesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;
use NFePHP\Common\Validator;
use Tests\Concerns\GeraCertificados;
use Tests\Concerns\SefazFalsa;
use Tests\TestCase;

/** Simples remessa, bonificação e devolução de compra (com nota referenciada e impostos devolvidos). */
class OperacoesNotaTest extends TestCase
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

    /** Chave válida de uma NF-e emitida pelo fornecedor. */
    private function chaveDoFornecedor(int $numero = 4567, string $cnpj = '11444777000161'): string
    {
        $base = '32'.'2610'.$cnpj.'55'.'001'.str_pad((string) $numero, 9, '0', STR_PAD_LEFT).'1'.'12345678';

        return $base.ChaveAcesso::digito($base);
    }

    private function dados(string $operacao, array $extra = []): array
    {
        return array_merge([
            'operacao' => $operacao,
            'destinatario_id' => $this->fornecedor->id,
            'natureza_operacao' => '',
            'modalidade_frete' => 9,
            'forma_pagamento' => '17',
            'ind_pagamento' => 0,
            'itens' => [
                ['produto_id' => $this->produto->id, 'quantidade' => '2', 'valor_unitario' => '49,90', 'cfop' => ''],
            ],
        ], $extra);
    }

    private function emitir(array $dados): Nota
    {
        $this->actingAs($this->user)->post('/empresa/notas', $dados + ['acao' => 'emitir'])->assertSessionHasNoErrors();

        return Nota::latest('id')->firstOrFail();
    }

    private function xmlEnviado(Nota $nota): string
    {
        return (string) Storage::disk('local')->get(preg_replace('/procNFe\.xml$/', 'nfe.xml', $nota->xml_path));
    }

    /** Assina com o certificado de teste e valida no XSD oficial (PL_010). */
    private function assertXmlValidoNoSchema(Nota $nota): void
    {
        $xml = app(MontadorXmlNfe::class)->montar($nota->fresh())['xml'];
        $cert = Certificate::readPfx($this->pfx, 'senha-cert');
        $assinado = Signer::sign($cert, $xml, 'infNFe', 'Id', OPENSSL_ALGO_SHA1, [true, false, null, null]);
        $xsd = base_path('vendor/nfephp-org/sped-nfe/schemes/'.config('nfeexpress.nfe.schemes').'/nfe_v4.00.xsd');

        $this->assertTrue(Validator::isValid($assinado, $xsd));
    }

    public function test_formulario_mostra_as_operacoes(): void
    {
        $this->actingAs($this->user)->get('/empresa/notas/create')->assertOk()
            ->assertSee('Simples remessa')->assertSee('Bonificação / brinde')->assertSee('Devolução de compra')
            ->assertSee('Escolher o XML da nota de compra');
    }

    public function test_simples_remessa_usa_5949_e_sai_sem_pagamento(): void
    {
        $this->usarSefazFalsa('autorizada');
        $nota = $this->emitir($this->dados('remessa'));

        $this->assertSame(Nota::AUTORIZADA, $nota->status, (string) $nota->motivo);
        $this->assertSame('Simples remessa', $nota->natureza_operacao);
        $this->assertSame('5949', $nota->itens->first()->cfop);
        $this->assertSame('90', $nota->forma_pagamento);
        $xml = $this->xmlEnviado($nota);
        $this->assertStringContainsString('<tPag>90</tPag>', $xml);
        $this->assertStringContainsString('<finNFe>1</finNFe>', $xml);
        $this->assertXmlValidoNoSchema($nota);
    }

    public function test_bonificacao_usa_5910(): void
    {
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados('bonificacao'))->assertSessionHasNoErrors();

        $nota = Nota::latest('id')->first();
        $this->assertSame('5910', $nota->itens->first()->cfop);
        $this->assertSame('bonificacao', $nota->operacao);
    }

    public function test_devolucao_manual_com_icms_destacado_e_nota_referenciada(): void
    {
        $this->usarSefazFalsa('autorizada');
        $chave = $this->chaveDoFornecedor();

        $nota = $this->emitir($this->dados('devolucao', [
            'referencias' => [['tipo' => 'nfe', 'chave' => trim(chunk_split($chave, 4, ' '))]],
            'itens' => [[
                'produto_id' => $this->produto->id, 'quantidade' => '2', 'valor_unitario' => '49,90', 'cfop' => '',
                'base_icms' => '99,80', 'aliq_icms' => '17,00', 'valor_icms' => '16,97', 'valor_ipi_devolvido' => '4,99',
            ]],
        ]));

        $this->assertSame(Nota::AUTORIZADA, $nota->status, (string) $nota->motivo);
        $this->assertSame(4, $nota->finalidade);
        $this->assertSame('5202', $nota->itens->first()->cfop);
        $this->assertSame('900', $nota->itens->first()->cst_csosn);
        $this->assertSame([['tipo' => 'nfe', 'chave' => $chave]], $nota->referencias);
        $this->assertSame('104.79', $nota->valor_total); // 99,80 + IPI devolvido 4,99

        $xml = $this->xmlEnviado($nota);
        $this->assertStringContainsString('<finNFe>4</finNFe>', $xml);
        $this->assertStringContainsString("<refNFe>{$chave}</refNFe>", $xml);
        $this->assertStringContainsString('<CSOSN>900</CSOSN>', $xml);
        $this->assertStringContainsString('<vICMS>16.97</vICMS>', $xml);
        $this->assertStringContainsString('<vIPIDevol>4.99</vIPIDevol>', $xml);
        $this->assertStringContainsString('<vNF>104.79</vNF>', $xml);
        $this->assertStringContainsString('Devolucao referente a NF-e n. 4567 serie 1', $xml);
        $this->assertXmlValidoNoSchema($nota);
    }

    public function test_nota_em_papel_e_mais_de_uma_referencia_nao_sao_aceitas(): void
    {
        // NT 2025.002-RTC: a devolução referencia uma única NF-e, pela chave
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados('devolucao', [
            'referencias' => [['tipo' => 'nf', 'uf' => 'ES', 'mes_ano' => '09/2026', 'cnpj' => '11444777000161', 'modelo' => '01', 'serie' => '1', 'numero' => '321']],
        ]))->assertSessionHasErrors('referencias.0.tipo');

        $this->actingAs($this->user)->post('/empresa/notas', $this->dados('devolucao', [
            'referencias' => [['tipo' => 'nfe', 'chave' => $this->chaveDoFornecedor(1)], ['tipo' => 'nfe', 'chave' => $this->chaveDoFornecedor(2)]],
        ]))->assertSessionHasErrors('referencias');
    }

    public function test_regras_da_devolucao(): void
    {
        // Sem nota de compra
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados('devolucao'))->assertSessionHasErrors('referencias');

        // Chave inválida
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados('devolucao', ['referencias' => [['tipo' => 'nfe', 'chave' => '1234']]]))
            ->assertSessionHasErrors('referencias.0.chave');

        // CFOP de venda na devolução e CFOP de devolução na venda
        $ref = ['referencias' => [['tipo' => 'nfe', 'chave' => $this->chaveDoFornecedor()]]];
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados('devolucao', $ref + [
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario' => '10,00', 'cfop' => '5102']],
        ]))->assertSessionHasErrors('itens.0.cfop');
        $this->actingAs($this->user)->post('/empresa/notas', $this->dados('venda', [
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario' => '10,00', 'cfop' => '5202']],
        ]))->assertSessionHasErrors('itens.0.cfop');
    }

    public function test_devolucao_para_quem_nao_emitiu_a_nota_de_compra_e_avisada(): void
    {
        $outro = $this->empresa->destinatarios()->create($this->fornecedor->only([
            'tipo_pessoa', 'razao_social', 'ind_ie_dest', 'inscricao_estadual', 'logradouro', 'numero', 'bairro',
            'codigo_municipio', 'municipio', 'uf', 'cep',
        ]) + ['cpf_cnpj' => '45723174000110']);

        $this->actingAs($this->user)->post('/empresa/notas', $this->dados('devolucao', [
            'destinatario_id' => $outro->id,
            'referencias' => [['tipo' => 'nfe', 'chave' => $this->chaveDoFornecedor()]],
        ]) + ['acao' => 'emitir'])->assertSessionHasErrors('emissao');
    }

    // ---------------- Importação do XML da nota de compra ----------------

    private function xmlCompra(string $destinatario = '11222333000181'): string
    {
        $chave = $this->chaveDoFornecedor(9876, '98765432000198');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe{$chave}" versao="4.00">
<ide><cUF>32</cUF><natOp>Venda</natOp><mod>55</mod><serie>1</serie><nNF>9876</nNF><tpNF>1</tpNF></ide>
<emit><CNPJ>98765432000198</CNPJ><xNome>FABRICA DE ROUPAS SA</xNome><xFant>FABRICA</xFant>
<enderEmit><xLgr>Rua Industrial</xLgr><nro>500</nro><xBairro>Distrito</xBairro><cMun>3205200</cMun><xMun>Vila Velha</xMun><UF>ES</UF><CEP>29100000</CEP><fone>2733330000</fone></enderEmit>
<IE>82513333</IE><CRT>3</CRT></emit>
<dest><CNPJ>{$destinatario}</CNPJ><xNome>EMPRESA TESTE LTDA</xNome></dest>
<det nItem="1"><prod><cProd>CAM-01</cProd><cEAN>SEM GTIN</cEAN><xProd>CAMISETA BRANCA M</xProd><NCM>61091000</NCM><CFOP>5101</CFOP>
<uCom>UN</uCom><qCom>10.0000</qCom><vUnCom>20.0000000000</vUnCom><vProd>200.00</vProd><vDesc>10.00</vDesc></prod>
<imposto><ICMS><ICMS00><orig>0</orig><CST>00</CST><modBC>3</modBC><vBC>190.00</vBC><pICMS>17.00</pICMS><vICMS>32.30</vICMS></ICMS00></ICMS>
<IPI><cEnq>999</cEnq><IPITrib><CST>50</CST><vBC>190.00</vBC><pIPI>5.00</pIPI><vIPI>9.50</vIPI></IPITrib></IPI></imposto></det>
<det nItem="2"><prod><cProd>BON-02</cProd><cEAN>SEM GTIN</cEAN><xProd>BONE AZUL</xProd><NCM>65050019</NCM><CFOP>5405</CFOP>
<uCom>UN</uCom><qCom>4.0000</qCom><vUnCom>15.0000000000</vUnCom><vProd>60.00</vProd></prod>
<imposto><ICMS><ICMS10><orig>0</orig><CST>10</CST><modBC>3</modBC><vBC>60.00</vBC><pICMS>17.00</pICMS><vICMS>10.20</vICMS><modBCST>4</modBCST><vBCST>84.00</vBCST><pICMSST>17.00</pICMSST><vICMSST>4.08</vICMSST></ICMS10></ICMS></imposto></det>
</infNFe></NFe></nfeProc>
XML;
    }

    private function importar(string $xml): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post('/empresa/notas/importar-compra', [
            'xml' => UploadedFile::fake()->createWithContent('compra.xml', $xml),
        ]);
    }

    public function test_importa_xml_da_compra_e_monta_a_devolucao(): void
    {
        $resposta = $this->importar($this->xmlCompra());
        $nota = Nota::latest('id')->firstOrFail();
        $resposta->assertRedirect("/empresa/notas/{$nota->id}/edit");

        // Fornecedor cadastrado a partir do XML (IE com o zero à esquerda)
        $fornecedor = Destinatario::where('cpf_cnpj', '98765432000198')->firstOrFail();
        $this->assertSame('082513333', $fornecedor->inscricao_estadual);
        $this->assertSame($fornecedor->id, $nota->destinatario_id);

        $this->assertSame('devolucao', $nota->operacao);
        $this->assertSame($this->chaveDoFornecedor(9876, '98765432000198'), $nota->referencias[0]['chave']);
        [$camiseta, $bone] = $nota->itens->all();
        $this->assertSame(['5202', '5411'], [$camiseta->cfop, $bone->cfop]); // o boné teve ST
        $this->assertSame('32.30', $camiseta->valor_icms);
        $this->assertSame('9.50', $camiseta->valor_ipi_devolvido);
        $this->assertSame('4.08', $bone->valor_icms_st);
        $this->assertSame('260.00', $nota->valor_produtos);
        $this->assertSame('263.58', $nota->valor_total); // 260 - desconto 10 + ST 4,08 + IPI 9,50
        $nota->update(['numero' => 1]); // só para montar o XML de conferência
        $this->assertXmlValidoNoSchema($nota);

        // A tela de edição abre com os itens importados
        $this->actingAs($this->user)->get("/empresa/notas/{$nota->id}/edit")->assertOk()->assertSee('CAMISETA BRANCA M');
    }

    public function test_devolucao_parcial_tem_impostos_proporcionais(): void
    {
        $this->usarSefazFalsa('autorizada');
        $this->importar($this->xmlCompra());
        $nota = Nota::latest('id')->firstOrFail();
        $camiseta = $nota->itens->first();

        // Devolve só 4 das 10 camisetas e tira o boné
        $this->actingAs($this->user)->put("/empresa/notas/{$nota->id}", [
            'operacao' => 'devolucao',
            'destinatario_id' => $nota->destinatario_id,
            'natureza_operacao' => 'Devolucao de compra',
            'modalidade_frete' => 9,
            'referencias' => $nota->referencias,
            'itens' => [[
                'codigo' => $camiseta->codigo, 'descricao' => $camiseta->descricao, 'ncm' => $camiseta->ncm,
                'unidade' => $camiseta->unidade, 'gtin' => $camiseta->gtin, 'origem' => 0,
                'entrada' => json_encode($camiseta->tributos_extras['entrada']),
                'quantidade' => '4', 'valor_unitario' => '999,00', 'cfop' => '5.202',
                'valor_icms' => '1000,00', // ignorado: vem da nota de compra
            ]],
            'acao' => 'emitir',
        ])->assertSessionHasNoErrors();

        $nota->refresh();
        $item = $nota->itens->first();
        $this->assertSame(Nota::AUTORIZADA, $nota->status, (string) $nota->motivo);
        $this->assertSame('20.0000', $item->valor_unitario);      // preço da compra
        $this->assertSame('4.00', $item->valor_desconto);         // 10,00 x 40%
        $this->assertSame('76.00', $item->base_icms);             // 190,00 x 40%
        $this->assertSame('12.92', $item->valor_icms);            // 32,30 x 40%
        $this->assertSame('3.80', $item->valor_ipi_devolvido);    // 9,50 x 40%
        $this->assertSame('40.00', $item->perc_devolvido);
        $this->assertSame('79.80', $nota->valor_total);           // 80 - 4 + 3,80

        $xml = $this->xmlEnviado($nota);
        $this->assertStringContainsString('<pDevol>40.00</pDevol>', $xml);
        $this->assertXmlValidoNoSchema($nota);
    }

    public function test_nao_devolve_mais_do_que_comprou(): void
    {
        $this->importar($this->xmlCompra());
        $nota = Nota::latest('id')->firstOrFail();
        $camiseta = $nota->itens->first();

        $this->actingAs($this->user)->put("/empresa/notas/{$nota->id}", [
            'operacao' => 'devolucao', 'destinatario_id' => $nota->destinatario_id, 'modalidade_frete' => 9,
            'referencias' => $nota->referencias,
            'itens' => [[
                'codigo' => $camiseta->codigo, 'descricao' => $camiseta->descricao, 'ncm' => $camiseta->ncm, 'unidade' => 'UN',
                'entrada' => json_encode($camiseta->tributos_extras['entrada']), 'quantidade' => '11', 'valor_unitario' => '20,00', 'cfop' => '5202',
            ]],
        ])->assertSessionHasErrors('itens.0.quantidade');
    }

    public function test_xml_de_outra_empresa_ou_invalido_e_recusado(): void
    {
        $this->importar($this->xmlCompra('45723174000110'))->assertSessionHasErrors('xml');
        $this->importar('isto não é xml')->assertSessionHasErrors('xml');
        $this->assertSame(0, Nota::count());
    }
}
