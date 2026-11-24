<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use App\Support\RejeicoesSefaz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RejeicoesSefazTest extends TestCase
{
    use RefreshDatabase;

    private function nota(User $user, int $codigo, string $motivo, array $extra = []): Nota
    {
        return Nota::create($extra + [
            'empresa_id' => $user->empresa_id, 'user_id' => $user->id, 'modelo' => 55, 'serie' => 1, 'numero' => 7,
            'ambiente' => 2, 'status' => Nota::REJEITADA, 'natureza_operacao' => 'Venda', 'destinatario_dados' => [],
            'codigo_status' => $codigo, 'motivo' => $motivo,
        ]);
    }

    public function test_rejeicao_conhecida_aparece_explicada_com_botao(): void
    {
        $user = User::factory()->create();
        $nota = $this->nota($user, 209, 'Rejeicao: IE do emitente invalida');

        $this->actingAs($user)->get("/empresa/notas/{$nota->id}")->assertOk()
            ->assertSee('A inscrição estadual da empresa está inválida.')
            ->assertSee('O que fazer:')
            ->assertSee('Abrir Dados da empresa')
            ->assertSee('Rejeicao: IE do emitente invalida'); // o texto técnico continua visível
    }

    public function test_emissor_nao_habilitado_nfce(): void
    {
        $user = User::factory()->create();
        $nota = $this->nota($user, 781, 'Rejeicao: Emissor nao habilitado para emissao da NF-e/NFC-e', ['modelo' => 65]);

        $this->actingAs($user)->get("/empresa/nfce/{$nota->id}")->assertOk()
            ->assertSee('não está habilitada na SEFAZ')->assertSee('credenciamento')->assertSee('Abrir a venda e enviar de novo');
    }

    public function test_rejeicao_desconhecida_mostra_o_texto_da_sefaz(): void
    {
        $user = User::factory()->create();
        $nota = $this->nota($user, 12345, 'Rejeicao: algo raro');

        $this->actingAs($user)->get("/empresa/notas/{$nota->id}")->assertOk()
            ->assertSee('A SEFAZ não autorizou a nota')->assertSee('Rejeicao: algo raro');
    }

    public function test_botao_de_corrigir_o_destinatario(): void
    {
        $user = User::factory()->create();
        $pessoa = $user->empresa->destinatarios()->create([
            'tipo_pessoa' => 'J', 'cpf_cnpj' => '11444777000161', 'razao_social' => 'Cliente', 'ind_ie_dest' => 1,
            'inscricao_estadual' => '081234565', 'logradouro' => 'Rua', 'numero' => '1', 'bairro' => 'B',
            'codigo_municipio' => '3205309', 'municipio' => 'Vitoria', 'uf' => 'ES', 'cep' => '29010000',
        ]);
        $nota = $this->nota($user, 233, 'Rejeicao: IE do destinatario nao cadastrada', ['destinatario_id' => $pessoa->id]);

        $this->assertSame(route('empresa.pessoas.edit', $pessoa->id), RejeicoesSefaz::explicar(233, $nota)['url']);
    }
}
