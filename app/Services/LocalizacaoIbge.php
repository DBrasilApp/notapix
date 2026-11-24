<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Descobre o código IBGE do município (exigido na NF-e) a partir da UF e do nome da cidade.
 * Usado quando o preenchimento pelo CEP não trouxe o código.
 */
class LocalizacaoIbge
{
    public function codigoMunicipio(string $uf, string $municipio): ?string
    {
        $alvo = $this->normalizar($municipio);

        foreach ($this->municipiosDaUf($uf) as $codigo => $nome) {
            if ($this->normalizar($nome) === $alvo) {
                return (string) $codigo;
            }
        }

        return null;
    }

    /** @return array<string, string> código => nome */
    public function municipiosDaUf(string $uf): array
    {
        $uf = strtoupper($uf);

        return Cache::remember("ibge.municipios.$uf", now()->addDays(30), function () use ($uf) {
            $resposta = Http::timeout(10)->acceptJson()
                ->get("https://servicodados.ibge.gov.br/api/v1/localidades/estados/$uf/municipios");

            if (! $resposta->successful()) {
                return [];
            }

            return collect($resposta->json())
                ->mapWithKeys(fn (array $m) => [(string) $m['id'] => $m['nome']])
                ->all();
        });
    }

    private function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }
}
