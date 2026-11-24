<?php

namespace Tests\Concerns;

/** Gera certificados A1 falsos (autoassinados) para os testes. */
trait GeraCertificados
{
    protected function gerarPfx(
        string $cnpj = '11222333000181',
        string $senha = 'senha-cert',
        int $diasValidade = 365,
        string $nome = 'EMPRESA TESTE LTDA',
    ): string {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $csr = openssl_csr_new(
            ['countryName' => 'BR', 'organizationName' => 'ICP-Brasil', 'commonName' => "{$nome}:{$cnpj}"],
            $chave,
            ['digest_alg' => 'sha256'],
        );

        // Validade negativa = certificado já vencido
        $cert = openssl_csr_sign($csr, null, $chave, $diasValidade, ['digest_alg' => 'sha256']);

        openssl_pkcs12_export($cert, $pfx, $chave, $senha);

        return $pfx;
    }

    /** Certificado no formato antigo (RC2/3DES), via linha de comando. Null se não houver openssl. */
    protected function gerarPfxLegado(string $cnpj = '11222333000181', string $senha = 'senha-cert'): ?string
    {
        $pasta = sys_get_temp_dir().'/pfx-teste-'.uniqid();
        mkdir($pasta);

        try {
            openssl_pkcs12_read($this->gerarPfx($cnpj, $senha), $partes, $senha);
            file_put_contents("$pasta/cert.pem", $partes['cert']);
            file_put_contents("$pasta/chave.pem", $partes['pkey']);

            $comando = sprintf(
                'openssl pkcs12 -export -legacy -in %s -inkey %s -out %s -passout pass:%s 2>&1',
                escapeshellarg("$pasta/cert.pem"), escapeshellarg("$pasta/chave.pem"),
                escapeshellarg("$pasta/legado.pfx"), escapeshellarg($senha),
            );
            exec($comando, $saida, $codigo);

            return $codigo === 0 ? file_get_contents("$pasta/legado.pfx") : null;
        } finally {
            array_map('unlink', glob("$pasta/*"));
            rmdir($pasta);
        }
    }
}
