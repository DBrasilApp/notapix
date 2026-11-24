<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Pagamento extends Model
{
    protected $table = 'pagamentos';

    protected $fillable = [
        'pacote_id', 'carteira_type', 'carteira_id', 'user_id', 'valor', 'gateway', 'gateway_id',
        'status', 'pix_copia_cola', 'qr_code_base64', 'expira_em', 'pago_em', 'webhook_dados',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'expira_em' => 'datetime',
            'pago_em' => 'datetime',
            'webhook_dados' => 'array',
        ];
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(Pacote::class);
    }

    /** Empresa ou Escritorio que recebe os créditos. */
    public function carteira(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
