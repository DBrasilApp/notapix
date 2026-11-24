<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valida os dígitos verificadores de um CNPJ numérico (14 dígitos). */
class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valido((string) $value)) {
            $fail('O :attribute informado não é válido.');
        }
    }

    public static function somenteNumeros(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor);
    }

    /** Gera um CNPJ válido aleatório (para testes e dados de exemplo). */
    public static function gerar(): string
    {
        $base = '';
        for ($i = 0; $i < 8; $i++) {
            $base .= random_int(0, 9);
        }
        $base .= '0001';

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $base[$i] * $peso;
            }
            $resto = $soma % 11;
            $base .= $resto < 2 ? 0 : 11 - $resto;
        }

        return self::valido($base) ? $base : self::gerar();
    }

    public static function valido(string $cnpj): bool
    {
        $cnpj = self::somenteNumeros($cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12, 13] as $posicao) {
            $pesos = $posicao === 12
                ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
                : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cnpj[$i] * $pesos[$i];
            }

            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $cnpj[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }
}
