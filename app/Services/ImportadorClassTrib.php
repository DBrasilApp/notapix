<?php

namespace App\Services;

use App\Models\ClassTrib;
use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use NFePHP\Common\Certificate;
use RuntimeException;

/**
 * Carrega a tabela oficial de cClassTrib do IBS/CBS (Portal da Conformidade Fácil).
 *
 *  - Arquivo que acompanha o sistema: database/data/cclasstrib.json
 *  - Atualização direto da fonte oficial (exige certificado ICP-Brasil):
 *      php artisan classtrib:importar --cnpj=<CNPJ de uma empresa com certificado>
 *
 * Os dois usam o mesmo formato: lista de CSTs, cada um com "ClassificacoesTributarias".
 */
class ImportadorClassTrib
{
    /** @return list<array<string, mixed>> */
    public static function lerArquivo(?string $arquivo = null): array
    {
        $arquivo ??= database_path('data/cclasstrib.json');
        $dados = json_decode((string) @file_get_contents($arquivo), true);

        if (! is_array($dados)) {
            throw new RuntimeException("Arquivo de cClassTrib inválido: {$arquivo}");
        }

        return $dados['cst'] ?? $dados;
    }

    /** Baixa a tabela da API oficial, usando o certificado da empresa (autenticação mútua). */
    public function baixar(Empresa $empresa): array
    {
        $certificado = $empresa->certificadoAtivo;
        if (! $certificado) {
            throw new RuntimeException('A empresa não tem certificado digital cadastrado.');
        }

        $cert = Certificate::readPfx($certificado->conteudoPfx(), $certificado->senha);
        $pasta = storage_path('app/private/tmp');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0700, true);
        }
        $arqCert = $pasta.'/cff-'.bin2hex(random_bytes(6)).'.pem';
        $arqChave = $arqCert.'.key';
        file_put_contents($arqCert, (string) $cert->publicKey);
        file_put_contents($arqChave, (string) $cert->privateKey);

        try {
            $resposta = Http::timeout(60)->acceptJson()
                ->withOptions(['cert' => $arqCert, 'ssl_key' => $arqChave])
                ->get(config('nfeexpress.classtrib_url'));
        } finally {
            @unlink($arqCert);
            @unlink($arqChave);
        }

        if (! $resposta->successful()) {
            throw new RuntimeException('A API da Conformidade Fácil respondeu com erro '.$resposta->status().'.');
        }

        // Guarda a última resposta, para conferência
        @file_put_contents(storage_path('app/private/tmp/cclasstrib-api.json'), $resposta->body());

        $dados = $resposta->json();
        if (! is_array($dados)) {
            throw new RuntimeException('Resposta inesperada da API da Conformidade Fácil (não é JSON). Início: '.mb_substr($resposta->body(), 0, 300));
        }

        return $dados;
    }

    /**
     * Substitui a tabela pelos dados informados. Devolve quantas foram gravadas.
     * Aceita o formato oficial em PascalCase (CodClassTrib) ou camelCase (codClassTrib),
     * com as classificações dentro de cada CST ou numa lista simples.
     */
    public function importar(array $dados): int
    {
        $agora = now();
        $data = fn ($v) => $v ? substr((string) $v, 0, 10) : null;
        $linhas = [];

        foreach ($this->classificacoes(self::chavesMinusculas($dados)) as [$c, $nomeCst]) {
            $codigo = str_pad((string) ($c['codclasstrib'] ?? $c['cclasstrib'] ?? ''), 6, '0', STR_PAD_LEFT);
            if (! preg_match('/^\d{6}$/', $codigo) || $codigo === '000000') {
                continue;
            }
            $descricao = trim((string) ($c['nomeclasstrib'] ?? $c['descricao'] ?? ''));
            $nomeCst = (string) ($nomeCst ?: ($c['nomecst'] ?? ($c['cstnavigation']['nomecst'] ?? '')));

            $linhas[$codigo] = [
                'codigo' => $codigo,
                'cst' => substr($codigo, 0, 3),
                'nome_cst' => $nomeCst,
                'descricao' => $descricao,
                'nome_reduzido' => mb_substr(trim((string) ($c['nomereduzido'] ?? '')), 0, 255) ?: null,
                'busca' => ClassTrib::normalizar($codigo.' '.$descricao.' '.$nomeCst),
                'perc_red_ibs' => (float) ($c['percredibs'] ?? 0),
                'perc_red_cbs' => (float) ($c['percredcbs'] ?? 0),
                'ind_nfe' => filter_var($c['indnfe'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'tipo_aliquota' => isset($c['tipoaliq']) && is_numeric($c['tipoaliq']) ? (int) $c['tipoaliq'] : null,
                'url_legislacao' => mb_substr((string) ($c['texurllegislacao'] ?? ''), 0, 255) ?: null,
                'inicio_vigencia' => $data($c['dthinivig'] ?? null),
                'fim_vigencia' => $data($c['dthfimvig'] ?? null),
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        if (count($linhas) < 50) {
            throw new RuntimeException('Tabela de cClassTrib incompleta ('.count($linhas).' códigos). Nada foi alterado. '
                .'Início da resposta: '.mb_substr(json_encode($dados, JSON_UNESCAPED_UNICODE), 0, 400));
        }

        DB::transaction(function () use ($linhas) {
            ClassTrib::query()->delete();
            foreach (array_chunk(array_values($linhas), 100) as $lote) {
                ClassTrib::insert($lote);
            }
        });

        return count($linhas);
    }

    /** Percorre a resposta e devolve [classificação, nome do CST] onde estiver. */
    private function classificacoes(array $no, string $nomeCst = ''): \Generator
    {
        if (isset($no['codclasstrib']) || isset($no['cclasstrib'])) {
            yield [$no, $nomeCst];

            return;
        }

        $nomeCst = is_string($no['nomecst'] ?? null) ? $no['nomecst'] : $nomeCst;

        foreach ($no as $chave => $valor) {
            if (is_array($valor) && $chave !== 'cstnavigation') {
                yield from $this->classificacoes($valor, $nomeCst);
            }
        }
    }

    private static function chavesMinusculas(array $dados): array
    {
        $saida = [];
        foreach ($dados as $chave => $valor) {
            $saida[is_string($chave) ? strtolower($chave) : $chave] = is_array($valor) ? self::chavesMinusculas($valor) : $valor;
        }

        return $saida;
    }
}
