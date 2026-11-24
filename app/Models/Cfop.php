<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Cfop extends Model
{
    protected $table = 'cfops';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Mostrados quando a pesquisa abre vazia: os mais usados por quem é do Simples. */
    public const MAIS_USADOS = ['5102', '5101', '5405', '5403', '5656', '5667', '5202', '5949', '5910', '5915', '6102', '6108', '6403', '6404', '6202', '6949'];

    protected function casts(): array
    {
        return [
            'ind_nfe' => 'boolean',
            'ind_devolucao' => 'boolean',
            'ind_retorno' => 'boolean',
            'ind_remessa' => 'boolean',
            'ind_anulacao' => 'boolean',
            'ind_combustivel' => 'boolean',
            'ind_exclui_ibs_cbs' => 'boolean',
            'inicio_vigencia' => 'date',
        ];
    }

    /** CFOPs de saída (5, 6 e 7) permitidos em NF-e: os que servem para uma nota emitida. */
    public function scopeSaidasNfe(Builder $query): Builder
    {
        return $query->where('ind_nfe', true)
            ->where(fn ($q) => $q->where('codigo', 'like', '5%')->orWhere('codigo', 'like', '6%')->orWhere('codigo', 'like', '7%'));
    }

    public function scopePesquisar(Builder $query, string $termo): Builder
    {
        $numeros = preg_replace('/\D/', '', $termo);

        if ($numeros !== '' && preg_match('/^[\d.\s]+$/', $termo)) {
            return $query->where('codigo', 'like', $numeros.'%');
        }

        foreach (preg_split('/\s+/', self::normalizar($termo), -1, PREG_SPLIT_NO_EMPTY) as $palavra) {
            if (mb_strlen($palavra) >= 2) {
                $query->where('busca', 'like', '%'.$palavra.'%');
            }
        }

        return $query;
    }

    public static function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }

    /** 5102 → 5.102 */
    public static function formatar(?string $codigo): string
    {
        $n = preg_replace('/\D/', '', (string) $codigo);

        return strlen($n) === 4 ? $n[0].'.'.substr($n, 1) : (string) $codigo;
    }

    public function paraJson(): array
    {
        return [
            'codigo' => $this->codigo,
            'codigo_formatado' => self::formatar($this->codigo),
            'descricao' => $this->titulo,
            'descricao_completa' => $this->titulo,
        ];
    }
}
