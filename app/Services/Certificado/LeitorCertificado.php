<?php

namespace App\Services\Certificado;

use Carbon\CarbonImmutable;

/**
 * Lê um certificado digital A1 (.pfx / .p12) e devolve titular, CNPJ e validade.
 *
 * Certificados emitidos há alguns anos usam criptografia antiga (RC2 / 3DES), que o
 * OpenSSL 3 do PHP não abre mais. Nesses casos o leitor tenta converter o arquivo com o
 * programa `openssl` do sistema (opção -legacy) e devolve o .pfx já no formato atual,
 * para que a assinatura das notas funcione depois sem depender dessa conversão.
 */
class LeitorCertificado
{
    public function __construct(private ?string $binarioOpenssl = null)
    {
        $this->binarioOpenssl ??= config('nfeexpress.openssl_bin', 'openssl');
    }

    /**
     * @return array{pfx: string, convertido: bool, titular: string, cnpj: ?string,
     *               valido_de: CarbonImmutable, valido_ate: CarbonImmutable}
     */
    public function ler(string $conteudoPfx, string $senha): array
    {
        $convertido = false;

        if (! $this->abrir($conteudoPfx, $senha, $partes, $erro)) {
            if (! $this->formatoAntigo($erro)) {
                throw new CertificadoInvalidoException('Não foi possível abrir o certificado. Confira a senha e se o arquivo é um certificado A1 (.pfx ou .p12).');
            }

            $conteudoPfx = $this->converterFormatoAntigo($conteudoPfx, $senha);
            $convertido = true;

            if (! $this->abrir($conteudoPfx, $senha, $partes, $erro)) {
                throw new CertificadoInvalidoException('O certificado foi convertido, mas ainda não pôde ser aberto. Fale com o suporte.');
            }
        }

        $info = openssl_x509_parse($partes['cert']);

        if (! $info) {
            throw new CertificadoInvalidoException('O arquivo não contém um certificado válido.');
        }

        $nomeComum = $this->nomeComum($info);

        return [
            'pfx' => $conteudoPfx,
            'convertido' => $convertido,
            'titular' => $this->titular($nomeComum),
            'cnpj' => $this->cnpj($nomeComum, $info),
            'valido_de' => CarbonImmutable::createFromTimestamp($info['validFrom_time_t']),
            'valido_ate' => CarbonImmutable::createFromTimestamp($info['validTo_time_t']),
        ];
    }

    private function abrir(string $pfx, string $senha, ?array &$partes, ?string &$erro): bool
    {
        $partes = [];
        $ok = @openssl_pkcs12_read($pfx, $partes, $senha);

        $erros = [];
        while ($mensagem = openssl_error_string()) {
            $erros[] = $mensagem;
        }
        $erro = implode(' | ', $erros);

        return $ok && ! empty($partes['cert']) && ! empty($partes['pkey']);
    }

    /** "unsupported" = algoritmo antigo. "mac verify failure" = senha errada. */
    private function formatoAntigo(?string $erro): bool
    {
        return $erro !== null
            && str_contains(strtolower($erro), 'unsupported')
            && ! str_contains(strtolower($erro), 'mac verify');
    }

    private function converterFormatoAntigo(string $pfx, string $senha): string
    {
        $binario = trim((string) $this->binarioOpenssl, " \t\"'");
        $temCaminho = str_contains($binario, '/') || str_contains($binario, '\\');

        if ($temCaminho && ! is_file($binario)) {
            throw new CertificadoInvalidoException(
                "Este certificado está em formato antigo e precisa ser convertido, mas o programa openssl não foi encontrado em: {$binario}. ".
                'Confira a linha OPENSSL_BIN no arquivo .env.'
            );
        }

        // Tudo é feito numa pasta própria do sistema (storage), sem depender da pasta
        // temporária do Windows/PHP, que pode não existir ou não ter permissão.
        $pasta = storage_path('app/private/tmp');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0700, true);
        }
        $base = $pasta.DIRECTORY_SEPARATOR.'pfx-'.bin2hex(random_bytes(8));
        $arquivos = [
            'entrada' => "{$base}.pfx",
            'senha' => "{$base}.pass",    // a senha vai por arquivo, nunca pela linha de comando
            'saida' => "{$base}.pem",
            'erros' => "{$base}.err",
        ];

        file_put_contents($arquivos['entrada'], $pfx);
        file_put_contents($arquivos['senha'], $senha);

        $falha = null;
        $saidaErro = null;

        try {
            // OpenSSL 3 precisa de "-legacy" para ler RC2/3DES. O OpenSSL 1.1 (o que vem
            // com o Git para Windows) lê direto e não conhece essa opção: tenta sem ela.
            foreach ([['-legacy'], []] as $extra) {
                @unlink($arquivos['saida']);
                @unlink($arquivos['erros']);

                $processo = @proc_open(
                    array_merge([$binario, 'pkcs12'], $extra, ['-in', $arquivos['entrada'], '-nodes',
                        '-passin', 'file:'.$arquivos['senha'], '-out', $arquivos['saida']]),
                    [
                        0 => ['pipe', 'r'],
                        1 => ['file', $arquivos['erros'], 'a'],
                        2 => ['file', $arquivos['erros'], 'a'],
                    ],
                    $pipes,
                );

                if (! is_resource($processo)) {
                    $falha = error_get_last()['message'] ?? 'o programa não pôde ser iniciado';
                    break;
                }

                fclose($pipes[0]);
                proc_close($processo);

                $saidaErro = is_file($arquivos['erros']) ? strtolower((string) file_get_contents($arquivos['erros'])) : '';
                $opcaoDesconhecida = $extra && (str_contains($saidaErro, 'legacy') && (str_contains($saidaErro, 'unrecognized') || str_contains($saidaErro, 'unknown')));

                if (! $opcaoDesconhecida) {
                    break;
                }
            }

            $pem = is_file($arquivos['saida']) ? (string) file_get_contents($arquivos['saida']) : '';
            $saidaErro ??= '';
        } catch (\Throwable $e) {
            $falha = $e->getMessage();
            $pem = '';
            $saidaErro = '';
        } finally {
            foreach ($arquivos as $arquivo) {
                @unlink($arquivo);
            }
        }

        if (! str_contains($pem, 'PRIVATE KEY') || ! str_contains($pem, 'CERTIFICATE')) {
            if (str_contains($saidaErro, 'mac verify') || str_contains($saidaErro, 'invalid password')) {
                throw new CertificadoInvalidoException('Senha do certificado incorreta.');
            }

            // Detalhe técnico vai para o log (storage/logs/laravel.log) para diagnóstico
            \Illuminate\Support\Facades\Log::warning('Falha ao converter certificado antigo', [
                'openssl' => $binario,
                'erro' => $falha,
                'saida' => mb_substr($saidaErro, 0, 500),
            ]);

            $detalhe = $falha ?: trim(mb_substr($saidaErro, 0, 200));

            throw new CertificadoInvalidoException(
                'Este certificado usa um formato de criptografia antigo e não pôde ser convertido automaticamente. '.
                ($detalhe ? "Detalhe ({$binario}): {$detalhe}. " : '').
                'Confira a linha OPENSSL_BIN no .env ou exporte o certificado novamente pelo Windows escolhendo a criptografia AES256-SHA256.'
            );
        }

        return $this->reexportar($pem, $senha);
    }

    /** Monta um novo .pfx (formato atual, AES) com a mesma senha. */
    private function reexportar(string $pem, string $senha): string
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $certificados);
        preg_match('/-----BEGIN (?:RSA |ENCRYPTED )?PRIVATE KEY-----.+?-----END (?:RSA |ENCRYPTED )?PRIVATE KEY-----/s', $pem, $chave);

        $chavePrivada = openssl_pkey_get_private($chave[0] ?? '');
        if (! $chavePrivada || empty($certificados[0])) {
            throw new CertificadoInvalidoException('Não foi possível converter o certificado. Fale com o suporte.');
        }

        // O certificado da empresa é o que combina com a chave; os demais são da cadeia
        $principal = null;
        $cadeia = [];
        foreach ($certificados[0] as $cert) {
            if ($principal === null && openssl_x509_check_private_key($cert, $chavePrivada)) {
                $principal = $cert;
            } else {
                $cadeia[] = $cert;
            }
        }

        if ($principal === null
            || ! openssl_pkcs12_export($principal, $novoPfx, $chavePrivada, $senha, $cadeia ? ['extracerts' => $cadeia] : [])) {
            throw new CertificadoInvalidoException('Não foi possível converter o certificado. Fale com o suporte.');
        }

        return $novoPfx;
    }

    private function nomeComum(array $info): string
    {
        $cn = $info['subject']['CN'] ?? '';

        // Alguns certificados trazem mais de um CN; o da empresa é o que tem o CNPJ
        if (is_array($cn)) {
            $comCnpj = array_filter($cn, fn ($v) => preg_match('/\d{14}/', $v));
            $cn = reset($comCnpj) ?: implode(' ', $cn);
        }

        return trim((string) $cn);
    }

    /** No padrão ICP-Brasil o CN do e-CNPJ é "RAZAO SOCIAL:12345678000199". */
    private function titular(string $nomeComum): string
    {
        return trim(preg_replace('/:\d{14}$/', '', $nomeComum)) ?: $nomeComum;
    }

    private function cnpj(string $nomeComum, array $info): ?string
    {
        if (preg_match('/:(\d{14})$/', $nomeComum, $m)) {
            return $m[1];
        }

        // Alternativa: CNPJ no campo de nomes alternativos (OID 2.16.76.1.3.3)
        $alternativos = $info['extensions']['subjectAltName'] ?? '';
        if (preg_match('/2\.16\.76\.1\.3\.3\D*?(\d{14})/', $alternativos, $m)) {
            return $m[1];
        }

        return preg_match('/(\d{14})/', $nomeComum, $m) ? $m[1] : null;
    }
}
