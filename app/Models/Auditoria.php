<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Auditoria extends Model
{
    protected $table = 'auditoria';

    protected $fillable = ['user_id', 'acao', 'alvo_type', 'alvo_id', 'dados', 'ip'];

    protected function casts(): array
    {
        return ['dados' => 'array'];
    }

    /** Registra uma ação sensível. Nunca passe senhas ou certificados em $dados. */
    public static function registrar(string $acao, ?Model $alvo = null, array $dados = []): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'acao' => $acao,
            'alvo_type' => $alvo?->getMorphClass(),
            'alvo_id' => $alvo?->getKey(),
            'dados' => $dados ?: null,
            'ip' => request()?->ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alvo(): MorphTo
    {
        return $this->morphTo();
    }
}
