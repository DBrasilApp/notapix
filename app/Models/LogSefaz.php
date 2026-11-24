<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogSefaz extends Model
{
    protected $table = 'logs_sefaz';

    protected $fillable = [
        'empresa_id', 'nota_id', 'nfse_id', 'servico', 'ambiente', 'xml_envio',
        'xml_retorno', 'codigo_status', 'motivo', 'duracao_ms',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }
}
