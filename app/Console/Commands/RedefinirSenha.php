<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Escritorio;
use App\Models\User;
use App\Rules\Cnpj;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Mostra o e-mail de acesso e define uma nova senha (atendimento e testes). */
class RedefinirSenha extends Command
{
    protected $signature = 'usuario:senha {busca : CNPJ da empresa, CPF/CNPJ do escritório ou e-mail do usuário} {--senha= : Nova senha (se não informar, gera uma)}';

    protected $description = 'Mostra o login e redefine a senha de um usuário';

    public function handle(): int
    {
        $busca = trim((string) $this->argument('busca'));

        if (str_contains($busca, '@')) {
            $usuarios = User::where('email', mb_strtolower($busca))->get();
        } else {
            $cnpj = Cnpj::somenteNumeros($busca);
            $empresa = Empresa::where('cnpj', $cnpj)->value('id');
            $escritorio = Escritorio::where('cnpj', $cnpj)->value('id');
            $usuarios = User::query()
                ->where(fn ($q) => $q->when($empresa, fn ($q) => $q->orWhere('empresa_id', $empresa))
                    ->when($escritorio, fn ($q) => $q->orWhere('escritorio_id', $escritorio)))
                ->when(! $empresa && ! $escritorio, fn ($q) => $q->whereRaw('1 = 0'))
                ->get();
        }

        if ($usuarios->isEmpty()) {
            $this->error('Nenhum usuário encontrado.');

            return self::FAILURE;
        }

        if ($usuarios->count() > 1) {
            $this->warn('Há mais de um usuário. Rode de novo informando o e-mail de um deles:');
            $this->table(['Nome', 'E-mail (login)'], $usuarios->map(fn ($u) => [$u->name, $u->email]));

            return self::FAILURE;
        }

        $usuario = $usuarios->first();
        $senha = $this->option('senha') ?: Str::password(10, symbols: false);

        if (strlen($senha) < 8) {
            $this->error('A senha deve ter pelo menos 8 caracteres.');

            return self::FAILURE;
        }

        $usuario->forceFill(['password' => $senha])->save();

        $this->info('Senha redefinida.');
        $this->line("Login (e-mail): {$usuario->email}");
        $this->line("Nova senha:     {$senha}");

        return self::SUCCESS;
    }
}
