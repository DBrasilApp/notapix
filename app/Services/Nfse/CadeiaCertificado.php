<?php

namespace App\Services\Nfse;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use NFePHP\Common\Certificate;

/**
 * Cadeia do certificado A1 para a conexão com a SEFIN Nacional.
 *
 * Muitos arquivos .pfx trazem só o certificado da empresa, sem as autoridades intermediárias.
 * O servidor da SEFIN (IIS) pode recusar a conexão (403) se não conseguir montar a cadeia.
 * Quando o arquivo não traz a cadeia, ela é baixada da própria certificadora pelo endereço
 * gravado no certificado (AIA) e guardada em cache.
 */
final class CadeiaCertificado
{
    /** Certificado + intermediárias, em PEM. */
    public static function pem(Certificate $certificado): string
    {
        $pem = rtrim((string) $certificado->publicKey)."\n";
        $doArquivo = (string) $certificado->chainKeys;

        if (str_contains($doArquivo, 'BEGIN CERTIFICATE')) {
            return $pem.self::normalizar($doArquivo);
        }

        return $pem.self::baixar((string) $certificado->publicKey);
    }

    /** Intermediárias baixadas pelo AIA (até 4 níveis, sem a raiz). */
    public static function baixar(string $certPem): string
    {
        $chave = 'nfse.cadeia.'.sha1($certPem);

        return Cache::remember($chave, now()->addDays(30), function () use ($certPem) {
            $cadeia = '';
            $atual = $certPem;

            for ($nivel = 0; $nivel < 4; $nivel++) {
                $url = self::urlEmissor($atual);
                if (! $url) {
                    break;
                }

                try {
                    $resposta = Http::timeout(10)->get($url);
                } catch (\Throwable) {
                    break;
                }
                if (! $resposta->successful()) {
                    break;
                }

                $certs = self::lerCertificados((string) $resposta->body());
                if (! $certs) {
                    break;
                }

                $novo = false;
                foreach ($certs as $c) {
                    $info = openssl_x509_parse($c) ?: [];
                    if (($info['subject'] ?? null) === ($info['issuer'] ?? 0)) {
                        continue; // raiz: o servidor já tem
                    }
                    if (! str_contains($cadeia, trim($c))) {
                        $cadeia .= trim($c)."\n";
                        $atual = $c;
                        $novo = true;
                    }
                }
                if (! $novo) {
                    break;
                }
            }

            return $cadeia;
        });
    }

    private static function urlEmissor(string $certPem): ?string
    {
        $info = openssl_x509_parse($certPem) ?: [];
        $aia = (string) ($info['extensions']['authorityInfoAccess'] ?? '');

        return preg_match('/CA Issuers\s*-\s*URI:(\S+)/i', $aia, $m) ? $m[1] : null;
    }

    /** Aceita .crt/.cer (DER ou PEM) e .p7b/.p7c (DER ou PEM). */
    private static function lerCertificados(string $conteudo): array
    {
        if (str_contains($conteudo, 'BEGIN CERTIFICATE')) {
            preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $conteudo, $m);

            return $m[0];
        }

        // Certificado único em DER
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($conteudo), 64, "\n")."-----END CERTIFICATE-----\n";
        if (@openssl_x509_read($pem)) {
            return [$pem];
        }

        // PKCS#7 (.p7b) em DER ou PEM
        $p7 = str_contains($conteudo, 'BEGIN PKCS7')
            ? $conteudo
            : "-----BEGIN PKCS7-----\n".chunk_split(base64_encode($conteudo), 64, "\n")."-----END PKCS7-----\n";
        $certs = [];
        if (function_exists('openssl_pkcs7_read') && @openssl_pkcs7_read($p7, $certs)) {
            return $certs;
        }

        return [];
    }

    private static function normalizar(string $pem): string
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);

        return implode("\n", $m[0])."\n";
    }
}
