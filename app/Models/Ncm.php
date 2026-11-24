<?php

namespace App\Models;

use App\Support\Formata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Ncm extends Model
{
    protected $table = 'ncms';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['codigo', 'descricao', 'descricao_completa', 'busca', 'data_inicio', 'data_fim'];

    protected function casts(): array
    {
        return ['data_inicio' => 'date', 'data_fim' => 'date'];
    }

    /** Só os códigos em vigor hoje. */
    public function scopeVigentes(Builder $query): Builder
    {
        $hoje = now()->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('data_inicio')->orWhere('data_inicio', '<=', $hoje))
            ->where(fn ($q) => $q->whereNull('data_fim')->orWhere('data_fim', '>=', $hoje));
    }

    /**
     * Busca por código (começo dos números) ou por palavras da descrição.
     * Todas as palavras precisam aparecer, sem diferenciar acentos e maiúsculas.
     */
    public function scopePesquisar(Builder $query, string $termo): Builder
    {
        $numeros = preg_replace('/\D/', '', $termo);

        if ($numeros !== '' && preg_match('/^[\d.\s\-]+$/', $termo)) {
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

    public static function tabelaImportada(): bool
    {
        return static::query()->exists();
    }

    public function paraJson(): array
    {
        return [
            'codigo' => $this->codigo,
            'codigo_formatado' => Formata::ncm($this->codigo),
            'descricao' => $this->descricao,
            'descricao_completa' => $this->descricao_completa,
        ];
    }
}
