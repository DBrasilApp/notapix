<?php

namespace App\Support;

use App\Services\Nfe\MontadorXmlNfe;

/**
 * Chave de acesso de NF-e/NFC-e (44 posições):
 * UF(2) AAMM(4) CNPJ(14) modelo(2) série(3) número(9) tpEmis(1) código(8) DV(1).
 * O CNPJ pode ser alfanumérico (a partir de 2026); o DV usa o valor ASCII - 48 de cada caractere.
 */
final class ChaveAcesso
{
    public static function limpar(?string $chave): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $chave));
    }

    public static function valida(?string $chave): bool
    {
        $chave = self::limpar($chave);

        if (! preg_match('/^\d{6}[0-9A-Z]{12}\d{26}$/', $chave)) {
            return false;
        }

        return self::digito(substr($chave, 0, 43)) === (int) $chave[43];
    }

    public static function digito(string $base43): int
    {
        $soma = 0;
        $peso = 2;
        for ($i = strlen($base43) - 1; $i >= 0; $i--) {
            $soma += (ord($base43[$i]) - 48) * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    /** @return array{uf: ?string, mes: string, ano: string, cnpj: string, modelo: string, serie: int, numero: int}|null */
    public static function decompor(?string $chave): ?array
    {
        $chave = self::limpar($chave);
        if (! self::valida($chave)) {
            return null;
        }

        return [
            'uf' => array_search((int) substr($chave, 0, 2), MontadorXmlNfe::CODIGOS_UF, true) ?: null,
            'ano' => '20'.substr($chave, 2, 2),
            'mes' => substr($chave, 4, 2),
            'cnpj' => substr($chave, 6, 14),
            'modelo' => substr($chave, 20, 2),
            'serie' => (int) substr($chave, 22, 3),
            'numero' => (int) substr($chave, 25, 9),
        ];
    }
}
