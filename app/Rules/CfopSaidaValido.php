<?php

namespace App\Rules;

use App\Models\Cfop;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CFOP existente na tabela oficial, de saída (5, 6 ou 7) e permitido em NF-e. */
class CfopSaidaValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $codigo = preg_replace('/\D/', '', (string) $value);

        if (! preg_match('/^[5-7]\d{3}$/', $codigo)) {
            $fail('Use um CFOP de saída, com 4 dígitos começando por 5, 6 ou 7.');

            return;
        }

        if (! Cfop::query()->saidasNfe()->whereKey($codigo)->exists()) {
            $fail('Este CFOP não existe na tabela oficial ou não pode ser usado em NF-e.');
        }
    }
}
