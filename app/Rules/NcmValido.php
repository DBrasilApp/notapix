<?php

namespace App\Rules;

use App\Models\Ncm;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NCM com 8 dígitos e, se a tabela oficial já foi importada, existente e em vigor.
 * Códigos reduzidos (2, 4 ou 6 dígitos) são recusados: a SEFAZ rejeita a nota.
 */
class NcmValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $codigo = preg_replace('/\D/', '', (string) $value);

        if (strlen($codigo) !== 8) {
            $fail('O NCM deve ter 8 dígitos. Códigos reduzidos não são aceitos na NF-e.');

            return;
        }

        if (Ncm::tabelaImportada() && ! Ncm::query()->vigentes()->whereKey($codigo)->exists()) {
            $fail('Este NCM não existe na tabela oficial ou não está mais em vigor.');
        }
    }
}
