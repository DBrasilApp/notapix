<?php

namespace App\Support;

/** Converte números digitados no padrão brasileiro ("1.234,56") para o formato do banco ("1234.56"). */
final class NumeroBr
{
    public static function paraBanco(mixed $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }
        $valor = str_replace(['R$', ' ', '%'], '', $valor);
        if (str_contains($valor, ',')) {
            $valor = str_replace(',', '.', str_replace('.', '', $valor));
        }

        return $valor;
    }
}
