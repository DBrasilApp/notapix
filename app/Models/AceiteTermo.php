<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AceiteTermo extends Model
{
    protected $table = 'aceites_termos';

    protected $fillable = ['user_id', 'documento', 'versao', 'ip', 'aceito_em'];

    protected function casts(): array
    {
        return ['aceito_em' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
