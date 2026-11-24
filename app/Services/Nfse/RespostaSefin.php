<?php

namespace App\Services\Nfse;

/** Resposta da SEFIN Nacional, já descompactada. */
final class RespostaSefin
{
    /** Pedido chegou, mas não sabemos o resultado (tempo esgotado, erro 5xx sem corpo...). */
    public const INCERTA = 'incerta';

    public const SUCESSO = 'sucesso';

    public const RECUSADA = 'recusada';

    /**
     * @param  array<int, array{codigo: ?string, descricao: ?string, complemento: ?string}>  $erros
     */
    public function __construct(
        public readonly string $situacao,
        public readonly ?int $http = null,
        public readonly ?string $xml = null,          // NFS-e ou evento devolvido
        public readonly ?string $chave = null,
        public readonly array $erros = [],
        public readonly array $alertas = [],
        public readonly ?string $bruto = null,
        public readonly ?string $mensagem = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->situacao === self::SUCESSO;
    }

    public function codigo(): ?string
    {
        return $this->erros[0]['codigo'] ?? null;
    }

    /** Todas as mensagens de erro numa linha. */
    public function textoErros(): string
    {
        if (! $this->erros) {
            return (string) $this->mensagem;
        }

        return implode(' | ', array_map(function ($e) {
            $texto = trim(implode(' — ', array_filter([$e['descricao'] ?? null, $e['complemento'] ?? null])));

            return trim(($e['codigo'] ? "[{$e['codigo']}] " : '').$texto);
        }, $this->erros));
    }

    /** Interpreta o JSON devolvido pela SEFIN (os nomes dos campos variam entre maiúsculas e minúsculas). */
    public static function deJson(int $http, string $corpo, string $campoXml): self
    {
        $dados = json_decode($corpo, true);

        if (! is_array($dados)) {
            $situacao = $http >= 500 || $http === 408 || $http === 0 ? self::INCERTA : self::RECUSADA;

            $resumo = trim(preg_replace('/\s+/', ' ', strip_tags($corpo)));
            $mensagem = trim(self::explicarHttp($http).($resumo !== '' ? ' Resposta do servidor: "'.mb_substr($resumo, 0, 160).'"' : ''));

            return new self($situacao, $http, bruto: $corpo, mensagem: $mensagem ?: null);
        }

        $brutos = $dados['erros'] ?? $dados['Erros'] ?? $dados['erro'] ?? $dados['Erro'] ?? [];
        if (isset($brutos['codigo']) || isset($brutos['Codigo'])) {
            $brutos = [$brutos];
        }
        $erros = [];
        foreach ((array) $brutos as $e) {
            if (is_array($e)) {
                $erros[] = [
                    'codigo' => $e['codigo'] ?? $e['Codigo'] ?? null,
                    'descricao' => $e['descricao'] ?? $e['Descricao'] ?? $e['mensagem'] ?? $e['Mensagem'] ?? null,
                    'complemento' => $e['complemento'] ?? $e['Complemento'] ?? null,
                ];
            }
        }

        $alertas = array_values(array_filter(array_map(
            fn ($a) => is_array($a) ? ($a['descricao'] ?? $a['Descricao'] ?? $a['mensagem'] ?? null) : null,
            (array) ($dados['alertas'] ?? $dados['Alertas'] ?? [])
        )));

        if ($erros) {
            return new self(self::RECUSADA, $http, erros: $erros, alertas: $alertas, bruto: $corpo);
        }

        $b64 = $dados[$campoXml] ?? null;
        if (! $b64) {
            $situacao = $http >= 500 || ($http >= 200 && $http < 300) ? self::INCERTA : self::RECUSADA;

            return new self($situacao, $http, bruto: $corpo, mensagem: self::explicarHttp($http) ?? 'A SEFIN não devolveu o documento.');
        }

        $xml = self::descompactar($b64);
        $chave = $dados['chaveAcesso'] ?? $dados['ChaveAcesso'] ?? (preg_match('/Id="NFS(\d{50})"/', $xml, $m) ? $m[1] : null);

        return new self(self::SUCESSO, $http, xml: $xml, chave: $chave, alertas: $alertas, bruto: $corpo);
    }

    public static function compactar(string $xml): string
    {
        return base64_encode(gzencode($xml));
    }

    public static function descompactar(string $b64): string
    {
        $bin = base64_decode($b64);
        $xml = @gzdecode($bin);

        return $xml === false ? (string) $bin : $xml;
    }

    public static function explicarHttp(int $http): ?string
    {
        return match (true) {
            $http === 0 => 'Sem resposta da SEFIN Nacional.',
            $http === 401, $http === 403 => 'A SEFIN Nacional não aceitou a conexão com o certificado digital (HTTP '.$http.'). Rode "php artisan nfse:conexao" para testar.',
            $http === 404 => 'Endereço da SEFIN Nacional não encontrado (HTTP 404).',
            $http === 429 => 'Muitos envios em pouco tempo: a SEFIN Nacional pediu para aguardar (HTTP 429).',
            $http >= 500 => 'A SEFIN Nacional está com problemas (HTTP '.$http.'). Tente de novo em alguns minutos.',
            default => null,
        };
    }
}
