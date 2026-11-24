<?php

namespace App\Services\Nfe;

use App\Models\Empresa;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapCurl;
use NFePHP\NFe\Tools;

/** Cliente real da SEFAZ, usando a NFePHP. */
class ClienteSefazNfephp implements ClienteSefaz
{
    private Tools $tools;

    /** @param int $modelo 55 (NF-e) ou 65 (NFC-e): cada um tem seus endereços na SEFAZ */
    public function __construct(Empresa $empresa, int $modelo = 55)
    {
        $certificado = $empresa->certificadoAtivo;

        if (! $certificado) {
            throw new \RuntimeException('A empresa não tem certificado digital cadastrado.');
        }

        $config = json_encode([
            'atualizacao' => now()->format('Y-m-d H:i:s'),
            'tpAmb' => $empresa->ambiente,
            'razaosocial' => $empresa->razao_social,
            'siglaUF' => $empresa->uf,
            'cnpj' => $empresa->cnpj,
            'schemes' => config('nfeexpress.nfe.schemes'),
            'versao' => '4.00',
            'tokenIBPT' => '',
            // NFC-e: CSC do ambiente (o QR Code versão 3 não usa, a versão 2 exige)
            'CSC' => $modelo === 65 ? $empresa->cscAtual()['csc'] : '',
            'CSCid' => $modelo === 65 ? $empresa->cscAtual()['id'] : '',
            'aProxyConf' => ['proxyIp' => '', 'proxyPort' => '', 'proxyUser' => '', 'proxyPass' => ''],
        ]);

        $cert = Certificate::readPfx($certificado->conteudoPfx(), $certificado->senha);
        $this->tools = new Tools($config, $cert);
        $this->tools->model($modelo);

        // A NFePHP grava cópias temporárias do certificado para a conexão segura com a SEFAZ.
        // Por padrão usa a pasta temporária do sistema (no Windows pode ser C:\WINDOWS, sem
        // permissão). Aqui usamos uma pasta própria dentro de storage.
        $pasta = storage_path('app/private/tmp/sped/'.$empresa->id);
        if (! is_dir($pasta)) {
            mkdir($pasta, 0700, true);
        }
        $soap = new SoapCurl($cert);
        $soap->timeout(30);
        try {
            $soap->setTemporaryFolder(str_replace('\\', '/', $pasta));
        } catch (\RuntimeException) {
            // Certificado sem o identificador ICP-Brasil (só em testes): mantém o padrão
        }
        $this->tools->loadSoapClass($soap);
    }

    public function assinar(string $xml): string
    {
        return $this->tools->signNFe($xml);
    }

    public function autorizar(string $xmlAssinado, string $idLote): string
    {
        return $this->tools->sefazEnviaLote([$xmlAssinado], $idLote, 1);
    }

    public function consultarChave(string $chave): string
    {
        return $this->tools->sefazConsultaChave($chave);
    }

    /** Serviço de status da SEFAZ (diagnóstico: código 107 = em operação). */
    public function status(): string
    {
        return $this->tools->sefazStatus();
    }

    public function cancelar(string $chave, string $justificativa, string $protocolo): string
    {
        return $this->tools->sefazCancela($chave, $justificativa, $protocolo);
    }

    public function cartaCorrecao(string $chave, string $texto, int $sequencia): string
    {
        return $this->tools->sefazCCe($chave, $texto, $sequencia);
    }

    /** Consulta o cadastro de contribuintes (CNPJ ou IE) na UF informada. */
    public function consultarCadastro(string $uf, string $cnpj = '', string $ie = ''): string
    {
        return $this->tools->sefazCadastro($uf, $cnpj, $ie);
    }

    public function ultimaRequisicao(): string
    {
        return (string) $this->tools->lastRequest;
    }
}
