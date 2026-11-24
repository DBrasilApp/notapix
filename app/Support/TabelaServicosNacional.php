<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Lista nacional de serviços da NFS-e (código de tributação nacional, 6 dígitos: item, subitem e desdobro
 * da LC 116/2003). A tabela oficial é pequena (pouco mais de 300 códigos) e vem junto com o sistema.
 */
final class TabelaServicosNacional
{
    private static ?array $tabela = null;

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function todos(): array
    {
        return self::$tabela ??= require resource_path('data/nfse/ctribnac.php');
    }

    public static function existe(?string $codigo): bool
    {
        return isset(self::todos()[self::limpar($codigo)]);
    }

    public static function descricao(?string $codigo): ?string
    {
        return self::todos()[self::limpar($codigo)][0] ?? null;
    }

    /** Grupo extra que o código exige na DPS: 'obra' (construção civil) ou 'evento' (item 12), ou null. */
    public static function grupoExigido(?string $codigo): ?string
    {
        return self::todos()[self::limpar($codigo)][1] ?? null;
    }

    /** 010701 → 01.07.01 */
    public static function formatar(?string $codigo): string
    {
        $c = self::limpar($codigo);

        return strlen($c) === 6 ? substr($c, 0, 2).'.'.substr($c, 2, 2).'.'.substr($c, 4, 2) : $c;
    }

    public static function limpar(?string $codigo): string
    {
        return preg_replace('/\D/', '', (string) $codigo);
    }

    /** Pesquisa por código (com ou sem pontos) ou por palavras da descrição, sem acento. */
    public static function pesquisar(string $termo, int $limite = 30): array
    {
        $termo = trim($termo);
        $numeros = self::limpar($termo);
        $palavras = array_filter(explode(' ', Str::lower(Str::ascii($termo))), fn ($p) => mb_strlen($p) >= 2);

        $achados = [];
        foreach (self::todos() as $codigo => [$descricao]) {
            $texto = Str::lower(Str::ascii($descricao));
            $porCodigo = $numeros !== '' && str_starts_with((string) $codigo, $numeros) && ! preg_match('/[a-z]/i', $termo);
            $porTexto = $palavras && collect($palavras)->every(fn ($p) => str_contains($texto, $p));

            if ($porCodigo || $porTexto) {
                $achados[] = self::paraJson((string) $codigo);
                if (count($achados) >= $limite) {
                    break;
                }
            }
        }

        return $achados;
    }

    /** No formato usado pelo campo de pesquisa (partials.busca-tabela). */
    public static function paraJson(string $codigo): array
    {
        $descricao = self::descricao($codigo);
        $grupo = self::grupoExigido($codigo);

        return [
            'codigo' => $codigo,
            'codigo_formatado' => self::formatar($codigo),
            'descricao_completa' => $descricao.match ($grupo) {
                'obra' => ' (exige dados da obra: ainda não disponível no NotaPix)',
                'evento' => ' (exige dados do evento: ainda não disponível no NotaPix)',
                default => '',
            },
        ];
    }
}
