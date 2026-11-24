<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pacote extends Model
{
    protected $table = 'pacotes';

    protected $fillable = ['nome', 'quantidade_creditos', 'preco', 'validade_dias', 'publico', 'ativo'];

    protected function casts(): array
    {
        return [
            'quantidade_creditos' => 'integer',
            'preco' => 'decimal:2',
            'validade_dias' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(Pagamento::class);
    }
}
