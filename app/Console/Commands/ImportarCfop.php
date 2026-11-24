<?php

namespace App\Console\Commands;

use App\Services\ImportadorCfop;
use Illuminate\Console\Command;

class ImportarCfop extends Command
{
    protected $signature = 'cfop:importar {--arquivo= : Arquivo JSON da tabela (padrão: database/data/cfop.json)}';

    protected $description = 'Recarrega a tabela oficial de CFOP';

    public function handle(ImportadorCfop $importador): int
    {
        try {
            $total = $importador->importar($this->option('arquivo'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tabela CFOP carregada: {$total} códigos.");

        return self::SUCCESS;
    }
}
