<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CPF (11 dígitos) ou CNPJ (14 dígitos), com os dígitos verificadores conferidos. */
class CpfOuCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valido((string) $value)) {
            $fail('O CPF ou CNPJ informado não é válido.');
        }
    }

    public static function valido(string $documento): bool
    {
        $numeros = Cnpj::somenteNumeros($documento);

        return match (strlen($numeros)) {
            11 => Cpf::valido($numeros),
            14 => Cnpj::valido($numeros),
            default => false,
        };
    }
}
