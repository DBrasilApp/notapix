<?php

namespace App\Services;

use App\Models\Ncm;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Importa a tabela oficial NCM publicada pelo Siscomex (arquivo JSON).
 *
 * A tabela traz todos os níveis (capítulo "01", posição "01.01", subposição "0101.2"...),
 * mas a NF-e só aceita o código completo de 8 dígitos. Por isso só os de 8 dígitos são
 * guardados, e cada um recebe a descrição completa juntando as descrições dos níveis acima:
 * "Cavalos, asininos e muares, vivos › Cavalos › Reprodutores de raça pura".
 */
class ImportadorNcm
{
    public function baixar(): string
    {
        $resposta = Http::timeout(120)->retry(2, 2000)->get(config('nfeexpress.ncm_url'));

        if (! $resposta->successful()) {
            throw new RuntimeException('Não foi possível baixar a tabela NCM do Siscomex (HTTP '.$resposta->status().').');
        }

        return $resposta->body();
    }

    /** @return int quantidade de códigos de 8 dígitos importados */
    public function importar(string $json): int
    {
        $itens = $this->extrairItens($json);

        // Descrições de todos os níveis, por código numérico
        $descricoes = [];
        $completos = [];

        foreach ($itens as $item) {
            $codigo = preg_replace('/\D/', '', (string) ($item['Codigo'] ?? $item['codigo'] ?? ''));
            $descricao = $this->limpar((string) ($item['Descricao'] ?? $item['descricao'] ?? ''));

            if ($codigo === '' || $descricao === '') {
                continue;
            }

            $inicio = $this->data($item['Data_Inicio'] ?? $item['data_inicio'] ?? null);
            $fim = $this->data($item['Data_Fim'] ?? $item['data_fim'] ?? null);

            $descricoes[$codigo] = $descricao;

            if (strlen($codigo) === 8) {
                // Se o código aparecer mais de uma vez, vale a versão mais recente
                if (isset($completos[$codigo]) && $completos[$codigo]['data_inicio'] > $inicio) {
                    continue;
                }
                $completos[$codigo] = ['descricao' => $descricao, 'data_inicio' => $inicio, 'data_fim' => $fim];
            }
        }

        if (count($completos) < 100) {
            throw new RuntimeException('O arquivo não parece ser a tabela NCM completa ('.count($completos).' códigos de 8 dígitos).');
        }

        $agora = now();
        $linhas = [];

        foreach ($completos as $codigo => $dados) {
            // Níveis acima: posição (4), subposições (5 e 6), item (7)
            $caminho = [];
            foreach ([4, 5, 6, 7] as $tamanho) {
                $pai = substr($codigo, 0, $tamanho);
                if (isset($descricoes[$pai]) && ! in_array($descricoes[$pai], $caminho, true)) {
                    $caminho[] = $descricoes[$pai];
                }
            }
            $caminho[] = $dados['descricao'];

            $completa = implode(' › ', $caminho);

            $linhas[] = [
                'codigo' => (string) $codigo,
                'descricao' => $dados['descricao'],
                'descricao_completa' => $completa,
                'busca' => Ncm::normalizar($codigo.' '.$completa),
                'data_inicio' => $dados['data_inicio'],
                'data_fim' => $dados['data_fim'],
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        DB::transaction(function () use ($linhas) {
            Ncm::query()->delete();

            foreach (array_chunk($linhas, 500) as $lote) {
                Ncm::insert($lote);
            }
        });

        return count($linhas);
    }

    private function extrairItens(string $json): array
    {
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json); // remove BOM
        $dados = json_decode($json, true);

        if (! is_array($dados)) {
            throw new RuntimeException('O arquivo da tabela NCM não é um JSON válido.');
        }

        // Formato do Siscomex: {"Nomenclaturas": [ {...}, ... ]}. Aceita também a lista direto.
        foreach (['Nomenclaturas', 'nomenclaturas'] as $chave) {
            if (isset($dados[$chave]) && is_array($dados[$chave])) {
                return $dados[$chave];
            }
        }

        if (array_is_list($dados)) {
            return $dados;
        }

        throw new RuntimeException('Não encontramos a lista de NCM dentro do arquivo.');
    }

    /** Tira os tracinhos de nível ("-- Reprodutores") e o ponto final. */
    private function limpar(string $descricao): string
    {
        $descricao = html_entity_decode(strip_tags($descricao), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $descricao = preg_replace('/^[\s\-–—]+/u', '', $descricao);
        $descricao = preg_replace('/\s+/u', ' ', $descricao);

        return rtrim(trim($descricao), '.:;');
    }

    private function data(?string $valor): ?string
    {
        if (! $valor) {
            return null;
        }

        try {
            return str_contains($valor, '/')
                ? Carbon::createFromFormat('d/m/Y', $valor)->toDateString()
                : Carbon::parse($valor)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
