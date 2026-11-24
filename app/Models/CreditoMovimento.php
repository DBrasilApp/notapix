<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Extrato de créditos. Movimentos nunca são editados nem apagados:
 * correções são feitas com um novo movimento (estorno ou ajuste).
 */
class CreditoMovimento extends Model
{
    public const COMPRA = 'compra';
    public const CORTESIA = 'cortesia';
    public const RESERVA = 'reserva';
    public const ESTORNO = 'estorno';
    public const EXPIRACAO = 'expiracao';
    public const AJUSTE = 'ajuste';
    public const TRANSFERENCIA = 'transferencia';

    protected $table = 'creditos_movimentos';

    protected $fillable = [
        'carteira_type', 'carteira_id', 'tipo', 'quantidade', 'nota_id', 'nfse_id',
        'pagamento_id', 'validade_ate', 'user_id', 'observacao',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'integer',
            'validade_ate' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Movimentos de crédito não podem ser alterados.'));
        static::deleting(fn () => throw new \LogicException('Movimentos de crédito não podem ser apagados.'));
    }

    public function carteira(): MorphTo
    {
        return $this->morphTo();
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
