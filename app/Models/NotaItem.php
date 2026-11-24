<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaItem extends Model
{
    protected $table = 'nota_itens';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        $casts = [
            'quantidade' => 'decimal:4',
            'valor_unitario' => 'decimal:4',
            'tributos_extras' => 'array',
        ];

        foreach ([
            'valor_total', 'valor_desconto', 'valor_frete', 'base_icms', 'valor_icms', 'aliq_icms',
            'base_icms_st', 'aliq_icms_st', 'valor_icms_st', 'perc_devolvido', 'valor_ipi_devolvido',
            'valor_pis', 'valor_cofins', 'base_ibs_cbs', 'valor_ibs_uf', 'valor_ibs_mun', 'valor_cbs',
        ] as $campo) {
            $casts[$campo] = 'decimal:2';
        }

        return $casts;
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
