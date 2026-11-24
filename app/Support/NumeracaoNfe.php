<?php

namespace App\Support;

/**
 * Converte a pergunta da tela ("já emitiu NF-e antes? série e número da última nota")
 * nos campos guardados na empresa (serie_nfe e proximo_numero_nfe).
 */
final class NumeracaoNfe
{
    /** @return array{serie_nfe: mixed, proximo_numero_nfe: mixed} */
    public static function doFormulario(array $entrada): array
    {
        $jaEmitiu = $entrada['ja_emitiu'] ?? null;

        // Nada informado: série 1, começando do 1
        if ($jaEmitiu === null && ! isset($entrada['ultimo_numero_nfe']) && ! isset($entrada['proximo_numero_nfe'])) {
            return ['serie_nfe' => $entrada['serie_nfe'] ?? 1, 'proximo_numero_nfe' => 1];
        }

        // Nunca emitiu: começa do 1 na série 1
        if ($jaEmitiu === '0' || $jaEmitiu === 0) {
            return ['serie_nfe' => 1, 'proximo_numero_nfe' => 1];
        }

        $ultimo = $entrada['ultimo_numero_nfe'] ?? null;
        $proximo = is_numeric($ultimo) ? (int) $ultimo + 1 : ($entrada['proximo_numero_nfe'] ?? null);

        // "Sim" sem número: deixa a validação reclamar do campo vazio
        if ($jaEmitiu !== null && ! is_numeric($ultimo)) {
            $proximo = null;
        }

        return [
            'serie_nfe' => $entrada['serie_nfe'] ?? 1,
            'proximo_numero_nfe' => $proximo,
        ];
    }
}
