<?php

namespace Tests\Feature;

use App\Models\Certificado;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Certificado\LeitorCertificado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GeraCertificados;
use Tests\TestCase;

class CertificadoTest extends TestCase
{
    use GeraCertificados, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Empresa::factory()->create(['cnpj' => '11222333000181']);
        $this->user = User::factory()->create(['empresa_id' => $empresa->id]);
    }

    private function enviar(string $pfx, string $senha = 'senha-cert', string $nome = 'cert.pfx')
    {
        return $this->actingAs($this->user)->post('/empresa/certificado', [
            'arquivo' => UploadedFile::fake()->createWithContent($nome, $pfx),
            'senha' => $senha,
        ]);
    }

    public function test_tela_abre(): void
    {
        $this->actingAs($this->user)->get('/empresa/certificado')->assertOk()->assertSee('Enviar certificado');
    }

    public function test_envia_certificado_valido_e_guarda_criptografado(): void
    {
        $this->enviar($this->gerarPfx())->assertRedirect('/empresa/certificado')->assertSessionHasNoErrors();

        $cert = $this->user->empresa->certificadoAtivo()->first();

        $this->assertSame('EMPRESA TESTE LTDA', $cert->titular);
        $this->assertSame('11222333000181', $cert->cnpj_certificado);
        $this->assertTrue($cert->valido_ate->isFuture());

        // O .pfx guardado abre com a senha guardada
        $this->assertTrue(openssl_pkcs12_read($cert->conteudoPfx(), $partes, $cert->senha));

        // No banco, nada legível
        $bruto = DB::table('certificados')->first();
        $this->assertStringNotContainsString('senha-cert', $bruto->senha);

        $this->actingAs($this->user)->get('/empresa/certificado')->assertSee('EMPRESA TESTE LTDA')->assertSee('Válido');
    }

    public function test_certificado_da_filial_com_mesma_raiz_e_aceito(): void
    {
        // Mesma raiz 11222333, outro estabelecimento (filial)
        $this->enviar($this->gerarPfx(cnpj: '11222333000262'))->assertSessionHasNoErrors();

        $this->assertSame(1, Certificado::count());
    }

    public function test_senha_errada_e_recusada(): void
    {
        $this->enviar($this->gerarPfx(), senha: 'errada')->assertSessionHasErrors('arquivo');

        $this->assertSame(0, Certificado::count());
    }

    public function test_certificado_de_outro_cnpj_e_recusado(): void
    {
        $this->enviar($this->gerarPfx(cnpj: '99888777000166'))
            ->assertSessionHasErrors(['arquivo' => 'Este certificado é do CNPJ 99.888.777/0001-66, diferente do CNPJ da empresa (11.222.333/0001-81). Se o CNPJ da empresa estiver errado, corrija em Dados da empresa.']);
    }

    public function test_certificado_vencido_e_recusado(): void
    {
        $this->enviar($this->gerarPfx(diasValidade: -1))->assertSessionHasErrors('arquivo');

        $this->assertSame(0, Certificado::count());
    }

    public function test_arquivo_que_nao_e_pfx_e_recusado(): void
    {
        $this->enviar('isso nao e um certificado', nome: 'cert.txt')->assertSessionHasErrors('arquivo');
        $this->enviar('isso nao e um certificado')->assertSessionHasErrors('arquivo');
    }

    public function test_novo_certificado_substitui_o_anterior(): void
    {
        $this->enviar($this->gerarPfx(nome: 'PRIMEIRO'));
        $this->enviar($this->gerarPfx(nome: 'SEGUNDO'));

        $this->assertSame(1, Certificado::count());
        $this->assertSame('SEGUNDO', Certificado::first()->titular);
    }

    public function test_remover_certificado(): void
    {
        $this->enviar($this->gerarPfx());

        $this->actingAs($this->user)->delete('/empresa/certificado')->assertRedirect('/empresa/certificado');

        $this->assertSame(0, Certificado::count());
    }

    public function test_certificado_em_formato_antigo_e_convertido(): void
    {
        $legado = $this->gerarPfxLegado();

        if ($legado === null) {
            $this->markTestSkipped('Programa openssl não disponível para gerar certificado legado.');
        }

        // Confirma que o PHP sozinho não abre este arquivo
        $this->assertFalse(@openssl_pkcs12_read($legado, $partes, 'senha-cert'));

        $this->enviar($legado)->assertSessionHasNoErrors()->assertSessionHas('sucesso');

        // O que foi guardado já está no formato atual e abre direto no PHP
        $cert = Certificado::first();
        $this->assertTrue(openssl_pkcs12_read($cert->conteudoPfx(), $partes, 'senha-cert'));
    }

    public function test_formato_antigo_sem_openssl_disponivel_mostra_mensagem_clara(): void
    {
        $legado = $this->gerarPfxLegado();

        if ($legado === null) {
            $this->markTestSkipped('Programa openssl não disponível para gerar certificado legado.');
        }

        $this->app->instance(LeitorCertificado::class, new LeitorCertificado('programa-que-nao-existe'));

        $this->enviar($legado)->assertSessionHasErrors('arquivo');
        $this->assertStringContainsString('formato de criptografia antigo', session('errors')->first('arquivo'));
    }

    public function test_falha_de_upload_do_php_mostra_o_motivo(): void
    {
        $falha = new UploadedFile(tempnam(sys_get_temp_dir(), 'x'), 'cert.pfx', null, UPLOAD_ERR_CANT_WRITE, true);

        $this->actingAs($this->user)->post('/empresa/certificado', ['arquivo' => $falha, 'senha' => 'x'])
            ->assertSessionHasErrors('arquivo');

        $this->assertStringContainsString('pasta temporária', session('errors')->first('arquivo'));
    }

    public function test_caminho_do_openssl_inexistente_mostra_onde_procurou(): void
    {
        $legado = $this->gerarPfxLegado();

        if ($legado === null) {
            $this->markTestSkipped('Programa openssl não disponível para gerar certificado legado.');
        }

        $this->app->instance(LeitorCertificado::class, new LeitorCertificado('C:\\Pasta\\Inexistente\\openssl.exe'));

        $this->enviar($legado)->assertSessionHasErrors('arquivo');
        $this->assertStringContainsString('não foi encontrado em: C:\\Pasta\\Inexistente\\openssl.exe', session('errors')->first('arquivo'));
    }

    public function test_funciona_com_openssl_1_1_que_nao_conhece_a_opcao_legacy(): void
    {
        $legado = $this->gerarPfxLegado();

        if ($legado === null || PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Teste depende do openssl e de shell script.');
        }

        // Imita o OpenSSL 1.1 do Git para Windows: recusa "-legacy", mas lê o formato antigo
        $script = sys_get_temp_dir().'/openssl-11-'.uniqid();
        file_put_contents($script, implode("\n", [
            '#!/bin/sh',
            'for a in "$@"; do [ "$a" = "-legacy" ] && { echo "pkcs12: Unrecognized flag legacy" >&2; exit 1; }; done',
            'sub=$1; shift; exec openssl "$sub" -legacy "$@"',
        ])."\n");
        chmod($script, 0755);

        $this->app->instance(LeitorCertificado::class, new LeitorCertificado($script));

        $this->enviar($legado)->assertSessionHasNoErrors()->assertSessionHas('sucesso');
        unlink($script);

        $this->assertTrue(openssl_pkcs12_read(Certificado::first()->conteudoPfx(), $partes, 'senha-cert'));
    }

    public function test_outros_perfis_nao_acessam(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/empresa/certificado')->assertForbidden();
        // Escritório só entra pela empresa-cliente escolhida em "Acessar"
        $this->actingAs(User::factory()->escritorio()->create())->get('/empresa/certificado')->assertRedirect('/escritorio');
    }
}
