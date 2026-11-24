<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Rules\Cnpj;
use App\Services\Nfe\CadastroSefaz;
use App\Services\Nfe\ClienteSefazNfephp;
use Illuminate\Console\Command;

/**
 * Consulta na SEFAZ se um CNPJ (ou IE) está habilitado e credenciado para NF-e.
 * Usa o certificado de uma empresa já cadastrada no NotaPix. Não emite nada nem gasta crédito.
 */
class SefazCadastro extends Command
{
    protected $signature = 'sefaz:cadastro
        {certificado : CNPJ da empresa do NotaPix cujo certificado será usado}
        {consultar? : CNPJ a consultar (padrão: o mesmo do certificado)}
        {--ie= : Consultar pela inscrição estadual em vez do CNPJ}
        {--uf=ES : UF do cadastro}
        {--producao : Consultar no ambiente de produção (só leitura)}';

    protected $description = 'Consulta se um CNPJ/IE está credenciado para NF-e na SEFAZ';

    public function handle(): int
    {
        $empresa = Empresa::where('cnpj', Cnpj::somenteNumeros($this->argument('certificado')))->first();

        if (! $empresa) {
            $this->error('Empresa do certificado não encontrada no NotaPix.');

            return self::FAILURE;
        }

        $ie = preg_replace('/\D/', '', (string) $this->option('ie'));
        $cnpj = $ie ? '' : Cnpj::somenteNumeros($this->argument('consultar') ?: $empresa->cnpj);
        $uf = strtoupper((string) $this->option('uf'));

        // Só muda em memória: a empresa continua no ambiente dela
        $empresa->ambiente = $this->option('producao') ? 1 : 2;

        $this->line('Consultando '.($ie ? "IE {$ie}" : "CNPJ {$cnpj}")." em {$uf} (".($empresa->ambiente === 1 ? 'produção' : 'homologação').')...');

        try {
            $resposta = (new ClienteSefazNfephp($empresa))->consultarCadastro($uf, $cnpj, $ie);
        } catch (\Throwable $e) {
            $this->error('Falha ao consultar: '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $r = CadastroSefaz::interpretar($resposta);
        $this->newLine();
        $this->info("Resposta da SEFAZ: {$r['codigo']} - {$r['motivo']}");

        foreach ($r['contribuintes'] as $c) {
            $this->newLine();
            $this->table(['Campo', 'Valor'], [
                ['Nome', $c['nome']],
                ['Fantasia', $c['fantasia']],
                ['CNPJ/CPF', $c['cnpj']],
                ['Inscrição estadual', \App\Rules\InscricaoEstadual::normalizar($c['ie'], $uf)],
                ['Situação', CadastroSefaz::SITUACAO[$c['situacao']] ?? $c['situacao']],
                ['NF-e (modelo 55)', CadastroSefaz::CREDENCIAMENTO[$c['cred_nfe']] ?? ($c['cred_nfe'] ?? 'não informado')],
                ['Regime', $c['regime']],
                ['CNAE', $c['cnae']],
                ['Município', $c['municipio']],
                ['Início das atividades', $c['inicio']],
            ]);
        }

        if (! $r['contribuintes'] && $r['codigo'] !== 111) {
            $this->line($resposta);
        }

        return $r['codigo'] === 111 ? self::SUCCESS : self::FAILURE;
    }
}
