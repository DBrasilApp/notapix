<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificado extends Model
{
    protected $table = 'certificados';

    protected $fillable = [
        'empresa_id', 'arquivo_pfx', 'senha', 'cnpj_certificado',
        'titular', 'valido_de', 'valido_ate', 'ativo',
    ];

    /** Nunca devolver o certificado ou a senha em JSON/arrays. */
    protected $hidden = ['arquivo_pfx', 'senha'];

    protected function casts(): array
    {
        return [
            // Criptografados com a APP_KEY. Guarde a APP_KEY em local seguro:
            // sem ela os certificados não podem ser lidos.
            'arquivo_pfx' => 'encrypted',
            'senha' => 'encrypted',
            'valido_de' => 'datetime',
            'valido_ate' => 'datetime',
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** Conteúdo binário do .pfx, pronto para assinar (guardado em base64 + criptografado). */
    public function conteudoPfx(): string
    {
        return base64_decode($this->arquivo_pfx);
    }

    public function venceEmDias(): ?int
    {
        return $this->valido_ate ? (int) now()->diffInDays($this->valido_ate, false) : null;
    }
}
