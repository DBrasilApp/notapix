<?php

namespace App\Services;

use App\Models\Cfop;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega a tabela oficial de CFOP (Informe Técnico 2023.002 do Portal da NF-e)
 * a partir do arquivo database/data/cfop.json que acompanha o sistema.
 *
 * Quando sair uma nova versão da tabela, basta trocar o arquivo e rodar:
 *   php artisan cfop:importar
 */
class ImportadorCfop
{
    public function importar(?string $arquivo = null): int
    {
        $arquivo ??= database_path('data/cfop.json');
        $dados = json_decode((string) @file_get_contents($arquivo), true);

        if (! is_array($dados['cfops'] ?? null) || count($dados['cfops']) < 500) {
            throw new RuntimeException("Arquivo de CFOP inválido ou incompleto: {$arquivo}");
        }

        $agora = now();
        $linhas = array_map(fn (array $c) => [
            'codigo' => $c['codigo'],
            'titulo' => $c['titulo'],
            'descricao' => $c['descricao'] ?: null,
            'busca' => Cfop::normalizar($c['codigo'].' '.$c['titulo']),
            'ind_nfe' => (bool) $c['nfe'],
            'ind_devolucao' => (bool) $c['devolucao'],
            'ind_retorno' => (bool) $c['retorno'],
            'ind_remessa' => (bool) $c['remessa'],
            'ind_anulacao' => (bool) $c['anulacao'],
            'ind_combustivel' => (bool) $c['combustivel'],
            'ind_exclui_ibs_cbs' => (bool) $c['exclui_ibs_cbs'],
            'inicio_vigencia' => $c['inicio_vigencia'] ?: null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ], $dados['cfops']);

        DB::transaction(function () use ($linhas) {
            Cfop::query()->delete();

            foreach (array_chunk($linhas, 200) as $lote) {
                Cfop::insert($lote);
            }
        });

        return count($linhas);
    }
}
