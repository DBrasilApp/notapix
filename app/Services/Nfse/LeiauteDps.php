<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use Carbon\CarbonImmutable;

/**
 * Qual leiaute de DPS cada ambiente aceita.
 *
 * Os dois pacotes oficiais têm a mesma versão (1.01), mas a ordem dos campos mudou com a NT 009
 * (finNFSe obrigatório e descrição do serviço no fim do grupo cServ). A produção restrita (homologação)
 * já exige o novo desde 31/10/2026; a produção aceita o anterior até 30/11/2026 e passa a exigir o novo
 * em 01/12/2026. Os dois podem ser forçados no .env (NFSE_LEIAUTE_PRODUCAO / NFSE_LEIAUTE_HOMOLOGACAO).
 */
final class LeiauteDps
{
    public const NT009 = 'nt009';

    public const ANTERIOR = 'anterior';

    public static function para(int $ambiente, ?CarbonImmutable $agora = null): string
    {
        $chave = $ambiente === Empresa::AMBIENTE_PRODUCAO ? 'leiaute_producao' : 'leiaute_homologacao';
        $config = (string) config("nfeexpress.nfse.{$chave}", 'auto');

        if (in_array($config, [self::NT009, self::ANTERIOR], true)) {
            return $config;
        }

        if ($ambiente !== Empresa::AMBIENTE_PRODUCAO) {
            return self::NT009;
        }

        $virada = CarbonImmutable::parse((string) config('nfeexpress.nfse.virada_nt009_producao', '2026-12-01'), 'America/Sao_Paulo');

        return ($agora ?? CarbonImmutable::now('America/Sao_Paulo'))->greaterThanOrEqualTo($virada) ? self::NT009 : self::ANTERIOR;
    }
}
