<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Código de barras (GTIN-8, 12, 13 ou 14) com dígito verificador, ou "SEM GTIN". */
class Gtin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (strtoupper(trim((string) $value)) === 'SEM GTIN') {
            return;
        }

        if (! self::valido((string) $value)) {
            $fail('O código de barras não é válido. Se o produto não tiver, deixe em branco.');
        }
    }

    public static function valido(string $gtin): bool
    {
        if (! preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $gtin)) {
            return false;
        }

        $digitos = array_map('intval', str_split($gtin));
        $verificador = array_pop($digitos);

        // Da direita para a esquerda, pesos 3, 1, 3, 1...
        $soma = 0;
        foreach (array_reverse($digitos) as $i => $d) {
            $soma += $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $soma % 10) % 10 === $verificador;
    }
}
