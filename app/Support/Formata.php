<?php

namespace App\Support;

class Formata
{
    public static function cnpj(?string $cnpj): string
    {
        $n = preg_replace('/\D/', '', (string) $cnpj);

        return strlen($n) === 14
            ? vsprintf('%s.%s.%s/%s-%s', [substr($n, 0, 2), substr($n, 2, 3), substr($n, 5, 3), substr($n, 8, 4), substr($n, 12, 2)])
            : (string) $cnpj;
    }

    /** CPF ou CNPJ, conforme o tamanho. */
    public static function documento(?string $documento): string
    {
        $n = preg_replace('/\D/', '', (string) $documento);

        return strlen($n) === 11 ? self::cpf($n) : self::cnpj($documento);
    }

    /** "CPF" ou "CNPJ", conforme o tamanho. */
    public static function tipoDocumento(?string $documento): string
    {
        return strlen(preg_replace('/\D/', '', (string) $documento)) === 11 ? 'CPF' : 'CNPJ';
    }

    public static function cpf(?string $cpf): string
    {
        $n = preg_replace('/\D/', '', (string) $cpf);

        return strlen($n) === 11
            ? vsprintf('%s.%s.%s-%s', [substr($n, 0, 3), substr($n, 3, 3), substr($n, 6, 3), substr($n, 9, 2)])
            : (string) $cpf;
    }

    /** 61091000 → 6109.10.00, como aparece na tabela oficial. */
    public static function ncm(?string $ncm): string
    {
        $n = preg_replace('/\D/', '', (string) $ncm);

        return strlen($n) === 8
            ? substr($n, 0, 4).'.'.substr($n, 4, 2).'.'.substr($n, 6, 2)
            : (string) $ncm;
    }

    public static function moeda(float|string|null $valor, int $casas = 2): string
    {
        return 'R$ '.number_format((float) $valor, $casas, ',', '.');
    }
}
