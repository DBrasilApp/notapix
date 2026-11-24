<?php

namespace App\Models;

use App\Models\Concerns\TemCarteiraDeCreditos;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Escritorio extends Model
{
    use HasFactory, SoftDeletes, TemCarteiraDeCreditos;

    protected $table = 'escritorios';

    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cnpj', 'crc',
        'email', 'telefone', 'municipio', 'uf', 'ativo',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'escritorio_empresa')
            ->withPivot(['status', 'solicitado_por', 'usar_saldo_escritorio', 'autorizado_em', 'revogado_em'])
            ->withTimestamps();
    }

    /** Empresas que o escritório atende hoje. */
    public function empresasAtivas(): BelongsToMany
    {
        return $this->empresas()->wherePivot('status', 'ativo');
    }
}
