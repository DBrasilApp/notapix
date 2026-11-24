<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Rules\Cnpj;
use App\Services\Nfse\ClienteSefinHttp;
use Illuminate\Console\Command;
use NFePHP\Common\Certificate;

/**
 * Testa a conexão com a SEFIN Nacional (NFS-e) usando o certificado da empresa.
 * Consulta uma DPS que não existe: não emite nada nem gasta crédito.
 */
class NfseConexao extends Command
{
    protected $signature = 'nfse:conexao
        {cnpj : CNPJ da empresa do NotaPix}
        {--producao : Testar a produção (só leitura) em vez da produção restrita}';

    protected $description = 'Testa se a SEFIN Nacional (NFS-e) aceita o certificado da empresa';

    public function handle(): int
    {
        $empresa = Empresa::where('cnpj', Cnpj::somenteNumeros($this->argument('cnpj')))->first();
        if (! $empresa) {
            $this->error('Empresa não encontrada no NotaPix.');

            return self::FAILURE;
        }

        $registro = $empresa->certificadoAtivo;
        if (! $registro) {
            $this->error('A empresa não tem certificado cadastrado.');

            return self::FAILURE;
        }

        // Dados do certificado
        try {
            $cert = Certificate::readPfx($registro->conteudoPfx(), $registro->senha);
        } catch (\Throwable $e) {
            $this->error('Não foi possível abrir o certificado: '.$e->getMessage());

            return self::FAILURE;
        }
        $cadeia = substr_count((string) $cert->chainKeys, 'BEGIN CERTIFICATE');
        $this->table(['Certificado', ''], [
            ['Titular', $cert->getCompanyName()],
            ['CNPJ', $cert->getCnpj() ?: '(não informado)'],
            ['Validade', $cert->getValidFrom()->format('d/m/Y').' a '.$cert->getValidTo()->format('d/m/Y')],
            ['Certificados da cadeia no arquivo', (string) $cadeia],
            ['Cadeia enviada na conexão', (string) (substr_count(\App\Services\Nfse\CadeiaCertificado::pem($cert), 'BEGIN CERTIFICATE') - 1).' intermediária(s)'],
            ['PHP / cURL / SSL', PHP_VERSION.' / '.(curl_version()['version'] ?? '?').' / '.(curl_version()['ssl_version'] ?? '?')],
        ]);

        if ($cert->getCnpj() && substr($cert->getCnpj(), 0, 8) !== substr($empresa->cnpj, 0, 8)) {
            $this->warn('Atenção: o certificado é de outro CNPJ (raiz diferente da empresa).');
        }

        $empresa->ambiente = $this->option('producao') ? Empresa::AMBIENTE_PRODUCAO : Empresa::AMBIENTE_HOMOLOGACAO;
        $cliente = new ClienteSefinHttp($empresa);
        $this->line('Servidor: '.config($empresa->ambiente === 1 ? 'nfeexpress.nfse.url_producao' : 'nfeexpress.nfse.url_homologacao'));
        $this->newLine();

        $aceito = false;
        $base = $cliente->baseUrl();
        // Mesmo endereço com e sem "/API": a produção restrita usa /API/SefinNacional; a produção, /SefinNacional
        $alternativo = str_contains($base, '/API/') ? str_replace('/API/', '/', $base) : preg_replace('#(nfse\.gov\.br)/#', '$1/API/', $base, 1);
        $testes = [
            ['tls12', $base, 'TLS 1.2 + HTTP/1.1 (usado pelo NotaPix)'],
            ['padrao', $base, 'Padrão do cURL (TLS 1.3 / HTTP/2)'],
            ['tls12', $alternativo, 'Endereço alternativo '.$alternativo],
        ];
        $funcionouAlternativo = false;

        foreach ($testes as $i => [$modo, $url, $rotulo]) {
            $r = $cliente->testarConexao($modo, $url);
            $resumo = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($r['corpo']))), 0, 200);
            // Só vale resposta da aplicação (JSON): 404 em HTML é "endereço não existe"
            $ok = in_array($r['http'], [200, 404], true) && is_array(json_decode($r['corpo'], true));

            $this->line(($ok ? '<info>OK</info>     ' : '<error>FALHOU</error> ')."{$rotulo}: HTTP {$r['http']}".($resumo !== '' ? " - {$resumo}" : ''));
            $aceito = $aceito || ($ok && $i === 0);
            $funcionouAlternativo = $funcionouAlternativo || ($ok && $i === 2);
        }

        // Diagnóstico extra quando a SEFIN recusa: o mesmo certificado em outro serviço do sistema nacional
        if (! $aceito) {
            $navegador = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
            $dps = $base.'/dps/'.\App\Services\Nfse\MontadorDps::idDps($empresa, '89999', 999999999999999);
            $adn = ($empresa->ambiente === 1 ? 'https://adn.nfse.gov.br' : 'https://adn.producaorestrita.nfse.gov.br')
                .'/parametrizacao/'.$empresa->codigo_municipio.'/convenio';

            foreach ([
                [$dps, 'tls12', $navegador, 'SEFIN com identificação de navegador'],
                [$adn, 'tls12', null, 'ADN (parâmetros do município '.$empresa->codigo_municipio.')'],
            ] as [$url, $modo, $ua, $rotulo]) {
                $r = $cliente->testarUrl($url, $modo, $ua);
                $resumo = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($r['corpo']))), 0, 160);
                $ok = $r['http'] >= 200 && $r['http'] < 500 && $r['http'] !== 403 && $r['http'] !== 401;
                $this->line(($ok ? '<info>OK</info>     ' : '<error>FALHOU</error> ')."{$rotulo}: HTTP {$r['http']}".($resumo !== '' ? " - {$resumo}" : ''));
            }
        }

        if (! $aceito && $funcionouAlternativo) {
            $chave = $empresa->ambiente === 1 ? 'NFSE_URL_PRODUCAO' : 'NFSE_URL_HOMOLOGACAO';
            $this->newLine();
            $this->warn("O endereço alternativo funcionou. Coloque no .env:  {$chave}={$alternativo}");
            $this->warn('e rode: php artisan optimize:clear');
        }

        $this->newLine();
        if ($aceito) {
            $this->info('A SEFIN Nacional aceitou o certificado. A emissão deve funcionar.');

            return self::SUCCESS;
        }

        if ($empresa->ambiente === Empresa::AMBIENTE_HOMOLOGACAO) {
            $this->warn('A SEFIN de testes (produção restrita) recusou o acesso. Rode também com --producao:');
            $this->warn('se a produção responder OK, o certificado e o sistema estão certos e o bloqueio é só do ambiente de testes.');
        } else {
            $this->warn('A SEFIN Nacional não aceitou a conexão. Copie esta tela e mande para o suporte.');
        }

        return self::FAILURE;
    }
}
