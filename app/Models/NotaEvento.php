<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaEvento extends Model
{
    protected $table = 'nota_eventos';

    protected $fillable = [
        'nota_id', 'user_id', 'tipo', 'sequencia', 'texto', 'status',
        'protocolo', 'codigo_status', 'motivo', 'xml_path',
    ];

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
