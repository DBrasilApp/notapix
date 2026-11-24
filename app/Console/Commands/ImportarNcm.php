<?php

namespace App\Console\Commands;

use App\Services\ImportadorNcm;
use Illuminate\Console\Command;

class ImportarNcm extends Command
{
    protected $signature = 'ncm:importar {--arquivo= : Caminho de um arquivo JSON já baixado, em vez de baixar do Siscomex}';

    protected $description = 'Importa (ou atualiza) a tabela oficial NCM do Siscomex';

    public function handle(ImportadorNcm $importador): int
    {
        try {
            if ($arquivo = $this->option('arquivo')) {
                if (! is_readable($arquivo)) {
                    $this->error("Arquivo não encontrado: {$arquivo}");

                    return self::FAILURE;
                }
                $json = file_get_contents($arquivo);
            } else {
                $this->info('Baixando a tabela NCM do Siscomex...');
                $json = $importador->baixar();
            }

            $this->info('Importando...');
            $total = $importador->importar($json);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('Se o download falhar, baixe o arquivo pelo navegador em:');
            $this->line(config('nfeexpress.ncm_url'));
            $this->line('e rode: php artisan ncm:importar --arquivo="caminho\\do\\arquivo.json"');

            return self::FAILURE;
        }

        $this->info("Tabela NCM importada: {$total} códigos de 8 dígitos.");

        return self::SUCCESS;
    }
}
