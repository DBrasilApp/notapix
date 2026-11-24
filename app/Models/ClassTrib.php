<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Classificação Tributária do IBS/CBS (cClassTrib). Os 3 primeiros dígitos são o CST. */
class ClassTrib extends Model
{
    protected $table = 'class_tribs';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Mostrados quando a pesquisa abre vazia. */
    public const MAIS_USADOS = ['000001', '200003', '200013', '200014', '200034', '410001', '410003', '410014', '550001', '620001'];

    protected function casts(): array
    {
        return [
            'ind_nfe' => 'boolean',
            'perc_red_ibs' => 'float',
            'perc_red_cbs' => 'float',
            'inicio_vigencia' => 'date',
            'fim_vigencia' => 'date',
        ];
    }

    /** Em vigor hoje e permitidas na NF-e. */
    public function scopeParaNfe(Builder $query): Builder
    {
        return $query->where('ind_nfe', true)
            ->where(fn ($q) => $q->whereNull('fim_vigencia')->orWhere('fim_vigencia', '>', now()->toDateString()));
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

    /** Texto curto para listas: "CST 200 · redução de 60%" etc. */
    public function resumo(): string
    {
        $partes = ["CST {$this->cst} - {$this->nome_cst}"];
        $red = max($this->perc_red_ibs, $this->perc_red_cbs);
        if ($red > 0) {
            $partes[] = $red >= 100 ? 'alíquota zero' : 'redução de '.rtrim(rtrim(number_format($red, 2, ',', ''), '0'), ',').'%';
        }

        return implode(' · ', $partes);
    }

    public function paraJson(): array
    {
        return [
            'codigo' => $this->codigo,
            'codigo_formatado' => $this->codigo,
            'cst' => $this->cst,
            'descricao' => $this->descricao,
            'descricao_completa' => $this->descricao.' ('.$this->resumo().')',
        ];
    }
}
