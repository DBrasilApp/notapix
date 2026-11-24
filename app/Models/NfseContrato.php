<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente mensal: o mesmo serviço, para o mesmo tomador, todo mês (emissão em lote).
 * Na tela aparece como "Clientes mensais".
 */
class NfseContrato extends Model
{
    protected $table = 'nfse_contratos';

    protected $fillable = [
        'empresa_id', 'destinatario_id', 'servico_id', 'valor', 'discriminacao', 'iss_retido',
        'retencoes', 'ativo', 'reajustado_em', 'valor_anterior',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'valor_anterior' => 'decimal:2',
            'iss_retido' => 'boolean',
            'retencoes' => 'array',
            'ativo' => 'boolean',
            'reajustado_em' => 'date',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function tomador(): BelongsTo
    {
        return $this->belongsTo(Destinatario::class, 'destinatario_id')->withTrashed();
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class)->withTrashed();
    }

    public function nfses(): HasMany
    {
        return $this->hasMany(Nfse::class, 'contrato_id');
    }

    /** Texto da nota: o do cliente, ou o padrão do serviço. */
    public function discriminacaoFinal(): string
    {
        return filled($this->discriminacao) ? $this->discriminacao : (string) $this->servico?->discriminacao;
    }
}
