<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida a inscrição estadual do emitente.
 * Espírito Santo: 9 dígitos com dígito verificador (módulo 11).
 * Demais UFs: por enquanto só confere se tem entre 2 e 14 dígitos.
 */
class InscricaoEstadual implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $uf = strtoupper((string) ($this->data['uf'] ?? ''));
        $ie = (string) self::normalizar((string) $value, $uf);

        $valida = $uf === 'ES' ? self::validaEs($ie) : (strlen($ie) >= 2 && strlen($ie) <= 14);

        if (! $valida) {
            $fail('A inscrição estadual informada não é válida'.($uf === 'ES' ? ' para o Espírito Santo.' : '.'));
        }
    }

    /**
     * Tamanho fixo da IE em cada UF (as de tamanho variável ficam de fora).
     * A SEFAZ e alguns cadastros guardam a IE como número e perdem os zeros à esquerda
     * (082513333 vira 82513333): aqui eles são devolvidos.
     */
    public const TAMANHOS = [
        'AC' => 13, 'AL' => 9, 'AM' => 9, 'AP' => 9, 'CE' => 9, 'DF' => 13, 'ES' => 9, 'GO' => 9,
        'MA' => 9, 'MG' => 13, 'MS' => 9, 'MT' => 11, 'PA' => 9, 'PB' => 9, 'PE' => 9, 'PI' => 9,
        'PR' => 10, 'RJ' => 8, 'RO' => 14, 'RR' => 9, 'RS' => 10, 'SC' => 9, 'SE' => 9, 'SP' => 12,
    ];

    /** Só números, com os zeros à esquerda que faltarem para o tamanho da UF. */
    public static function normalizar(?string $ie, ?string $uf): ?string
    {
        $numeros = preg_replace('/\D/', '', (string) $ie);
        if ($numeros === '') {
            return null;
        }

        $tamanho = self::TAMANHOS[strtoupper((string) $uf)] ?? null;

        return $tamanho && strlen($numeros) < $tamanho && strlen($numeros) >= $tamanho - 2
            ? str_pad($numeros, $tamanho, '0', STR_PAD_LEFT)
            : $numeros;
    }

    public static function validaEs(string $ie): bool
    {
        if (strlen($ie) !== 9) {
            return false;
        }

        $soma = 0;
        foreach ([9, 8, 7, 6, 5, 4, 3, 2] as $i => $peso) {
            $soma += (int) $ie[$i] * $peso;
        }

        $resto = $soma % 11;
        $digito = $resto < 2 ? 0 : 11 - $resto;

        return (int) $ie[8] === $digito;
    }
}
