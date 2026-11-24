<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;

/**
 * Cliente real da SEFIN Nacional: REST/JSON com o certificado A1 da empresa na conexão (mTLS).
 * O XML vai compactado (gzip + base64) dentro do JSON.
 */
class ClienteSefinHttp implements ClienteSefin
{
    private Certificate $certificado;

    private string $baseUrl;

    private ?string $ultima = null;

    public function __construct(private Empresa $empresa)
    {
        $registro = $empresa->certificadoAtivo;
        if (! $registro) {
            throw new \RuntimeException('A empresa não tem certificado digital cadastrado.');
        }

        $this->certificado = Certificate::readPfx($registro->conteudoPfx(), $registro->senha);
        $this->baseUrl = rtrim((string) config(
            $empresa->ambiente === Empresa::AMBIENTE_PRODUCAO ? 'nfeexpress.nfse.url_producao' : 'nfeexpress.nfse.url_homologacao'
        ), '/');
    }

    public function assinar(string $xml, string $tag): string
    {
        $assinado = Signer::sign($this->certificado, $xml, $tag, 'Id', OPENSSL_ALGO_SHA1, [true, false, null, null]);

        // A SEFIN exige a declaração UTF-8 (sem ela: E1229)
        return '<?xml version="1.0" encoding="UTF-8"?>'.preg_replace('/^<\?xml[^>]*\?>\s*/', '', $assinado);
    }

    public function emitir(string $dpsAssinada): RespostaSefin
    {
        $this->ultima = $dpsAssinada;

        return $this->enviar('post', '/nfse', ['dpsXmlGZipB64' => RespostaSefin::compactar($dpsAssinada)], 'nfseXmlGZipB64');
    }

    public function chavePorDps(string $idDps): ?string
    {
        $r = $this->requisitar('get', '/dps/'.$idDps);
        if ($r['http'] === 404) {
            return null;
        }
        $dados = json_decode($r['corpo'], true);
        if ($r['http'] !== 200 || ! is_array($dados)) {
            throw new \RuntimeException(RespostaSefin::explicarHttp($r['http']) ?? 'Consulta da DPS falhou (HTTP '.$r['http'].').');
        }

        return $dados['chaveAcesso'] ?? $dados['ChaveAcesso'] ?? null;
    }

    public function consultarNfse(string $chave): RespostaSefin
    {
        return $this->enviar('get', '/nfse/'.$chave, null, 'nfseXmlGZipB64');
    }

    public function registrarEvento(string $chave, string $pedidoAssinado): RespostaSefin
    {
        $this->ultima = $pedidoAssinado;

        return $this->enviar('post', '/nfse/'.$chave.'/eventos', [
            'pedidoRegistroEventoXmlGZipB64' => RespostaSefin::compactar($pedidoAssinado),
        ], 'eventoXmlGZipB64');
    }

    public function ultimaRequisicao(): ?string
    {
        return $this->ultima;
    }

    private function enviar(string $metodo, string $caminho, ?array $json, string $campoXml): RespostaSefin
    {
        try {
            $r = $this->requisitar($metodo, $caminho, $json);
        } catch (ConnectionException $e) {
            // Não dá para saber se chegou: quem chama consulta antes de reenviar
            return new RespostaSefin(RespostaSefin::INCERTA, 0, mensagem: 'Sem resposta da SEFIN Nacional: '.$e->getMessage());
        }

        return RespostaSefin::deJson($r['http'], $r['corpo'], $campoXml);
    }

    /** @return array{http: int, corpo: string} */
    private function requisitar(string $metodo, string $caminho, ?array $json = null, ?string $modo = null, ?string $userAgent = null): array
    {
        [$certPem, $chavePem] = $this->arquivosTemporarios();
        $url = str_starts_with($caminho, 'https://') ? $caminho : $this->baseUrl.$caminho;

        try {
            $resposta = $this->http($certPem, $chavePem, $modo ?? (string) config('nfeexpress.nfse.modo_tls', 'tls12'), $userAgent)
                ->{$metodo}($url, $json ?? []);

            return ['http' => $resposta->status(), 'corpo' => (string) $resposta->body()];
        } finally {
            @unlink($certPem);
            @unlink($chavePem);
        }
    }

    /**
     * Teste de conexão (php artisan nfse:conexao): consulta uma DPS que não existe.
     * Com o certificado aceito a SEFIN responde 404; recusado, 403.
     *
     * @return array{http: int, corpo: string}
     */
    public function testarConexao(string $modo, ?string $baseUrl = null): array
    {
        $idInexistente = MontadorDps::idDps($this->empresa, '89999', 999999999999999);
        $original = $this->baseUrl;
        $this->baseUrl = rtrim($baseUrl ?? $original, '/');

        try {
            return $this->requisitar('get', '/dps/'.$idInexistente, null, $modo);
        } catch (ConnectionException $e) {
            return ['http' => 0, 'corpo' => $e->getMessage()];
        } finally {
            $this->baseUrl = $original;
        }
    }

    /** GET em qualquer endereço do sistema nacional com o certificado (diagnóstico). */
    public function testarUrl(string $url, string $modo = 'tls12', ?string $userAgent = null): array
    {
        try {
            return $this->requisitar('get', $url, null, $modo, $userAgent);
        } catch (ConnectionException $e) {
            return ['http' => 0, 'corpo' => $e->getMessage()];
        }
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Modos da conexão segura:
     *  - tls12 (padrão): TLS 1.2 e HTTP/1.1. O servidor da SEFIN (IIS) pede o certificado do cliente
     *    depois de abrir a conexão (renegociação), o que não existe no TLS 1.3 nem no HTTP/2: nesses
     *    casos o certificado não chega e a resposta é "403 - Forbidden: Access is denied".
     *  - padrao: deixa o cURL escolher (para comparação no teste de conexão).
     */
    private function http(string $certPem, string $chavePem, string $modo, ?string $userAgent = null): PendingRequest
    {
        $opcoes = [
            'cert' => $certPem,
            'ssl_key' => $chavePem,
            'verify' => config('nfeexpress.nfse.verificar_ssl', true),
        ];

        if ($modo === 'tls12') {
            // Opções próprias do Guzzle (ele recusa as mesmas configurações passadas direto ao cURL)
            $opcoes['version'] = '1.1';
            $opcoes['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            $opcoes['crypto_method_max'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        return Http::acceptJson()
            ->asJson()
            ->withUserAgent($userAgent ?? 'NotaPix/1.0 (emissor NFS-e)')
            ->timeout((int) config('nfeexpress.nfse.timeout', 60))
            ->connectTimeout(15)
            ->withOptions($opcoes);
    }

    /** O certificado precisa estar em arquivo para a conexão segura: grava, usa e apaga em seguida. */
    private function arquivosTemporarios(): array
    {
        $pasta = storage_path('app/private/tmp/nfse/'.$this->empresa->id);
        if (! is_dir($pasta)) {
            mkdir($pasta, 0700, true);
        }

        $base = $pasta.DIRECTORY_SEPARATOR.Str::random(24);
        $cert = $base.'-cert.pem';
        $chave = $base.'-key.pem';

        file_put_contents($cert, CadeiaCertificado::pem($this->certificado)); // certificado + intermediárias, em PEM
        file_put_contents($chave, (string) $this->certificado->privateKey);
        @chmod($cert, 0600);
        @chmod($chave, 0600);

        return [$cert, $chave];
    }
}
