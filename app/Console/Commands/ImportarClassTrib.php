<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Rules\Cnpj;
use App\Services\ImportadorClassTrib;
use Illuminate\Console\Command;

class ImportarClassTrib extends Command
{
    protected $signature = 'classtrib:importar
        {--cnpj= : Baixa da API oficial usando o certificado desta empresa}
        {--arquivo= : Arquivo JSON no formato oficial (padrão: database/data/cclasstrib.json)}';

    protected $description = 'Carrega a tabela oficial de cClassTrib (IBS/CBS)';

    public function handle(ImportadorClassTrib $importador): int
    {
        try {
            if ($cnpj = $this->option('cnpj')) {
                $empresa = Empresa::where('cnpj', Cnpj::somenteNumeros($cnpj))->first();
                if (! $empresa) {
                    $this->error('Empresa não encontrada.');

                    return self::FAILURE;
                }
                $this->line('Baixando da API da Conformidade Fácil...');
                $dados = $importador->baixar($empresa);
            } else {
                $dados = ImportadorClassTrib::lerArquivo($this->option('arquivo'));
            }

            $total = $importador->importar($dados);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tabela cClassTrib carregada: {$total} códigos.");

        return self::SUCCESS;
    }
}
