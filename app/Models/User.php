<?php

namespace App\Models;

use App\Enums\Perfil;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'perfil',
        'escritorio_id',
        'empresa_id',
        'ativo',
        'ultimo_acesso_em',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'ultimo_acesso_em' => 'datetime',
            'password' => 'hashed',
            'perfil' => Perfil::class,
            'ativo' => 'boolean',
        ];
    }

    public function escritorio(): BelongsTo
    {
        return $this->belongsTo(Escritorio::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function aceitesTermos(): HasMany
    {
        return $this->hasMany(AceiteTermo::class);
    }

    public function isAdmin(): bool
    {
        return $this->perfil === Perfil::Admin;
    }

    public function isEscritorio(): bool
    {
        return $this->perfil === Perfil::Escritorio;
    }

    public function isEmpresa(): bool
    {
        return $this->perfil === Perfil::Empresa;
    }

    public function temPerfil(Perfil ...$perfis): bool
    {
        return in_array($this->perfil, $perfis, true);
    }

    /**
     * Empresas que este usuário pode acessar.
     * Admin: todas. Escritório: as vinculadas e ativas. Empresa: a própria.
     */
    public function empresasAcessiveis()
    {
        return match ($this->perfil) {
            Perfil::Admin => Empresa::query(),
            Perfil::Escritorio => $this->escritorio
                ? $this->escritorio->empresas()->wherePivot('status', 'ativo')
                : Empresa::query()->whereRaw('1 = 0'),
            Perfil::Empresa => Empresa::query()->whereKey($this->empresa_id),
        };
    }

    public function podeAcessarEmpresa(Empresa $empresa): bool
    {
        return $this->empresasAcessiveis()->whereKey($empresa->getKey())->exists();
    }
}
