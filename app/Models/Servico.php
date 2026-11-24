<?php

namespace App\Models;

use App\Support\TabelaServicosNacional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Serviço prestado pela empresa, com o código da lista nacional e o texto padrão da NFS-e. */
class Servico extends Model
{
    use SoftDeletes;

    protected $table = 'servicos';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'ctribnac', 'ctribmun', 'cnbs', 'discriminacao', 'valor', 'iss_retido',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'iss_retido' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** Ex.: 01.07.01 - Suporte técnico em informática... */
    public function codigoComDescricao(): string
    {
        return TabelaServicosNacional::formatar($this->ctribnac).' - '.(TabelaServicosNacional::descricao($this->ctribnac) ?? 'código não encontrado');
    }
}
