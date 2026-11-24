<?php

namespace App\Models\Concerns;

use App\Models\CreditoMovimento;
use App\Models\Pagamento;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Usado por Empresa e Escritorio: ambos podem ter créditos.
 * O saldo é sempre a soma dos movimentos, nunca um campo editado à mão.
 */
trait TemCarteiraDeCreditos
{
    public function creditosMovimentos(): MorphMany
    {
        return $this->morphMany(CreditoMovimento::class, 'carteira');
    }

    public function pagamentos(): MorphMany
    {
        return $this->morphMany(Pagamento::class, 'carteira');
    }

    public function saldoCreditos(): int
    {
        return (int) $this->creditosMovimentos()->sum('quantidade');
    }
}
