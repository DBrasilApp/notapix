<?php

namespace Database\Seeders;

use App\Enums\Perfil;
use App\Models\CreditoMovimento;
use App\Models\Empresa;
use App\Models\Escritorio;
use App\Models\Pacote;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->criarAdministrador();
        $this->criarPacotes();

        // Dados de exemplo só fora de produção
        if (! app()->isProduction()) {
            $this->criarDadosDeExemplo();
        }
    }

    private function criarAdministrador(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@notapix.local');
        $senha = env('ADMIN_SENHA') ?: Str::password(14, symbols: false);

        $admin = User::firstOrNew(['email' => $email]);

        if (! $admin->exists) {
            $admin->fill([
                'name' => env('ADMIN_NOME', 'Administrador'),
                'password' => $senha,
                'perfil' => Perfil::Admin,
                'ativo' => true,
            ]);
            $admin->email_verified_at = now();
            $admin->save();

            $this->command?->info("Administrador criado: {$email}");
            if (! env('ADMIN_SENHA')) {
                $this->command?->warn("Senha gerada (anote e troque depois): {$senha}");
            }
        }
    }

    /** Pacotes provisórios: preços finais serão definidos no fim do projeto. */
    private function criarPacotes(): void
    {
        $pacotes = [
            ['nome' => '1 nota', 'quantidade_creditos' => 1, 'preco' => 9.90, 'publico' => 'todos'],
            ['nome' => '5 notas', 'quantidade_creditos' => 5, 'preco' => 39.90, 'publico' => 'todos'],
            ['nome' => '10 notas', 'quantidade_creditos' => 10, 'preco' => 69.90, 'publico' => 'todos'],
            ['nome' => '30 notas', 'quantidade_creditos' => 30, 'preco' => 179.90, 'publico' => 'escritorio'],
        ];

        foreach ($pacotes as $pacote) {
            Pacote::firstOrCreate(['nome' => $pacote['nome']], $pacote + ['validade_dias' => 365]);
        }
    }

    private function criarDadosDeExemplo(): void
    {
        if (User::where('email', 'empresa@teste.local')->exists()) {
            return;
        }

        $empresa = Empresa::factory()->create(['razao_social' => 'Empresa Teste LTDA']);
        User::factory()->create([
            'name' => 'Usuário Empresa',
            'email' => 'empresa@teste.local',
            'empresa_id' => $empresa->id,
        ]);

        $escritorio = Escritorio::factory()->create(['razao_social' => 'Contabilidade Teste']);
        User::factory()->escritorio()->create([
            'name' => 'Usuário Escritório',
            'email' => 'escritorio@teste.local',
            'escritorio_id' => $escritorio->id,
        ]);

        $vinculada = Empresa::factory()->create(['razao_social' => 'Cliente do Escritório LTDA']);
        $escritorio->empresas()->attach($vinculada->id, ['status' => 'ativo', 'autorizado_em' => now()]);

        // Créditos de cortesia para testar em homologação
        foreach ([$empresa, $escritorio] as $carteira) {
            CreditoMovimento::create([
                'carteira_type' => $carteira->getMorphClass(),
                'carteira_id' => $carteira->id,
                'tipo' => CreditoMovimento::CORTESIA,
                'quantidade' => 10,
                'validade_ate' => now()->addYear(),
                'observacao' => 'Créditos de teste',
            ]);
        }

        $empresa->destinatarios()->create([
            'tipo_pessoa' => 'J', 'cpf_cnpj' => '11222333000181', 'razao_social' => 'Cliente Exemplo LTDA',
            'ind_ie_dest' => 9, 'logradouro' => 'Avenida Jerônimo Monteiro', 'numero' => '100', 'bairro' => 'Centro',
            'codigo_municipio' => '3205309', 'municipio' => 'Vitória', 'uf' => 'ES', 'cep' => '29010000',
        ]);
        $empresa->produtos()->create(\App\Models\Produto::PADROES_SIMPLES + [
            'codigo' => '1', 'descricao' => 'Camiseta de algodão', 'ncm' => '61091000', 'valor_unitario' => 49.90,
        ]);

        $this->command?->info('Usuários de teste: empresa@teste.local e escritorio@teste.local (senha: senha1234)');
    }
}
