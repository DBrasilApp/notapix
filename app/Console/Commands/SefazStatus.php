<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Rules\Cnpj;
use App\Services\Nfe\ClienteSefazNfephp;
use App\Services\Nfe\ResultadoSefaz;
use Illuminate\Console\Command;

/**
 * Testa a comunicação com a SEFAZ usando o certificado da empresa (serviço "status").
 * Não envia nota nem gasta crédito. Mostra o erro técnico completo se falhar.
 */
class SefazStatus extends Command
{
    protected $signature = 'sefaz:status {cnpj : CNPJ da empresa}';

    protected $description = 'Testa a comunicação com a SEFAZ (sem emitir nota)';

    public function handle(): int
    {
        $empresa = Empresa::where('cnpj', Cnpj::somenteNumeros($this->argument('cnpj')))->first();

        if (! $empresa) {
            $this->error('Empresa não encontrada.');

            return self::FAILURE;
        }

        $this->line("Empresa: {$empresa->razao_social} | UF: {$empresa->uf} | Ambiente: ".($empresa->ambiente === 1 ? 'produção' : 'homologação'));
        $this->line('PHP: '.PHP_VERSION.' | OpenSSL: '.OPENSSL_VERSION_TEXT.' | soap: '.(extension_loaded('soap') ? 'sim' : 'NÃO').' | curl: '.(extension_loaded('curl') ? 'sim' : 'NÃO'));

        // Se o PHP parar com erro fatal, ainda mostra o motivo
        register_shutdown_function(function () {
            $erro = error_get_last();
            if ($erro && in_array($erro['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                fwrite(STDOUT, PHP_EOL.'Erro fatal: '.$erro['message'].' em '.$erro['file'].':'.$erro['line'].PHP_EOL);
            }
        });

        $this->line('Pasta temporária do PHP: '.sys_get_temp_dir());
        $this->line('Conectando à SEFAZ...');

        try {
            $resposta = (new ClienteSefazNfephp($empresa))->status();
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Falha ao falar com a SEFAZ:');
            $this->line(get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $r = ResultadoSefaz::interpretar($resposta);
        $this->newLine();
        $this->info("Resposta da SEFAZ: {$r->codigo} - {$r->motivo}");

        if ($r->codigo !== 107) {
            $this->line($resposta);
        }

        return $r->codigo === 107 ? self::SUCCESS : self::FAILURE;
    }
}
