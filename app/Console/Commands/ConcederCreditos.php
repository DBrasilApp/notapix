<?php

namespace App\Console\Commands;

use App\Models\Auditoria;
use App\Models\CreditoMovimento;
use App\Models\Empresa;
use App\Models\Escritorio;
use App\Rules\Cnpj;
use App\Support\Formata;
use Illuminate\Console\Command;

/** Lança créditos de cortesia para uma empresa ou um escritório contábil (testes e atendimento). */
class ConcederCreditos extends Command
{
    protected $signature = 'creditos:conceder {cnpj : CNPJ da empresa ou do escritório} {quantidade : Quantidade de créditos}
        {--escritorio : Lançar para o escritório quando houver empresa e escritório com o mesmo CNPJ} {--motivo=Cortesia}';

    protected $description = 'Concede créditos de cortesia a uma empresa ou escritório contábil';

    public function handle(): int
    {
        $cnpj = Cnpj::somenteNumeros($this->argument('cnpj'));
        $quantidade = (int) $this->argument('quantidade');

        $empresa = Empresa::where('cnpj', $cnpj)->first();
        $escritorio = Escritorio::where('cnpj', $cnpj)->first();

        if ($empresa && $escritorio && ! $this->option('escritorio')) {
            $this->warn('Existe uma empresa e um escritório com este CNPJ: os créditos vão para a EMPRESA. Para o escritório, use --escritorio.');
        }

        // Empresa tem prioridade; com --escritorio (ou sem empresa), vai para o escritório
        $empresa = ($this->option('escritorio') || ! $empresa) ? $escritorio : $empresa;

        if (! $empresa) {
            $this->error('Nenhuma empresa ou escritório com o CNPJ '.Formata::cnpj($cnpj).'.');

            return self::FAILURE;
        }

        if ($quantidade < 1 || $quantidade > 1000) {
            $this->error('Informe uma quantidade entre 1 e 1000.');

            return self::FAILURE;
        }

        CreditoMovimento::create([
            'carteira_type' => $empresa->getMorphClass(),
            'carteira_id' => $empresa->id,
            'tipo' => CreditoMovimento::CORTESIA,
            'quantidade' => $quantidade,
            'validade_ate' => now()->addYear(),
            'observacao' => $this->option('motivo'),
        ]);

        Auditoria::registrar('creditos_concedidos', $empresa, ['quantidade' => $quantidade, 'motivo' => $this->option('motivo')]);

        $tipo = $empresa instanceof Escritorio ? 'o escritório' : 'a empresa';
        $this->info("{$quantidade} crédito(s) concedido(s) para {$tipo} {$empresa->razao_social}. Saldo atual: {$empresa->saldoCreditos()}.");

        return self::SUCCESS;
    }
}
