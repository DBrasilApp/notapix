<?php

namespace Tests\Concerns;

use App\Models\Empresa;
use App\Services\Nfse\ClienteSefin;
use App\Services\Nfse\ClienteSefinHttp;
use App\Services\Nfse\FabricaClienteSefin;
use App\Services\Nfse\LeiauteDps;
use App\Services\Nfse\RespostaSefin;
use NFePHP\Common\Signer;

/**
 * SEFIN Nacional falsa: assina de verdade e valida a DPS no XSD oficial do leiaute certo,
 * mas responde com mensagens prontas.
 */
trait SefinFalsa
{
    /** Respostas na ordem: 'autorizada', 'rejeitada', 'incerta', 'duplicada', e para eventos 'cancelado', 'nao_aceita'. */
    protected function usarSefinFalsa(string ...$respostas): FabricaClienteSefinFalsa
    {
        $fabrica = new FabricaClienteSefinFalsa($respostas);
        $this->app->instance(FabricaClienteSefin::class, $fabrica);

        return $fabrica;
    }
}

class FabricaClienteSefinFalsa extends FabricaClienteSefin
{
    public array $chamadas = [];

    public array $dpsEnviadas = [];

    public array $eventosEnviados = [];

    /** id da DPS => chave (o que a SEFIN "já tem") */
    public array $dpsConhecidas = [];

    public int $proximoNumero = 147;

    public string $ultimaNfse = '';

    public function __construct(public array $respostas)
    {
    }

    public function para(Empresa $empresa): ClienteSefin
    {
        return new ClienteSefinFalso($empresa, $this);
    }
}

class ClienteSefinFalso implements ClienteSefin
{
    private ClienteSefinHttp $real;

    private ?string $ultima = null;

    public function __construct(private Empresa $empresa, private FabricaClienteSefinFalsa $f)
    {
        $this->real = new ClienteSefinHttp($empresa);
    }

    public function assinar(string $xml, string $tag): string
    {
        return $this->real->assinar($xml, $tag);
    }

    public function emitir(string $dpsAssinada): RespostaSefin
    {
        $this->f->chamadas[] = 'emitir';
        $this->f->dpsEnviadas[] = $dpsAssinada;
        $this->ultima = $dpsAssinada;

        self::validarXsd($dpsAssinada, 'DPS_v1.01.xsd', (int) $this->tag($dpsAssinada, 'tpAmb'));
        if (! Signer::isSigned($dpsAssinada)) {
            throw new \RuntimeException('Assinatura inválida');
        }

        $resposta = array_shift($this->f->respostas) ?? 'autorizada';
        preg_match('/Id="(DPS\d{42})"/', $dpsAssinada, $m);
        $idDps = $m[1];

        return match ($resposta) {
            'incerta' => new RespostaSefin(RespostaSefin::INCERTA, 0, mensagem: 'Sem resposta da SEFIN Nacional: tempo esgotado'),
            'rejeitada' => RespostaSefin::deJson(400, json_encode(['tipoAmbiente' => 2, 'erros' => [[
                'Codigo' => 'E0312', 'Descricao' => 'Código de tributação nacional não administrado pelo município', 'Complemento' => null,
            ]]]), 'nfseXmlGZipB64'),
            'duplicada' => (function () use ($idDps, $dpsAssinada) {
                $this->f->dpsConhecidas[$idDps] = $this->chave();
                $this->f->ultimaNfse = $this->nfse($dpsAssinada, $this->f->dpsConhecidas[$idDps]);

                return RespostaSefin::deJson(400, json_encode(['erros' => [['codigo' => 'E0014', 'descricao' => 'DPS já existente']]]), 'nfseXmlGZipB64');
            })(),
            default => (function () use ($idDps, $dpsAssinada) {
                $chave = $this->chave();
                $this->f->dpsConhecidas[$idDps] = $chave;
                $xml = $this->nfse($dpsAssinada, $chave);
                $this->f->ultimaNfse = $xml;

                return RespostaSefin::deJson(201, json_encode([
                    'tipoAmbiente' => 2, 'versaoAplicativo' => 'SefinNac', 'dataHoraProcessamento' => now()->toIso8601String(),
                    'idDps' => $idDps, 'chaveAcesso' => $chave, 'nfseXmlGZipB64' => RespostaSefin::compactar($xml),
                ]), 'nfseXmlGZipB64');
            })(),
        };
    }

    public function chavePorDps(string $idDps): ?string
    {
        $this->f->chamadas[] = 'dps';

        return $this->f->dpsConhecidas[$idDps] ?? null;
    }

    public function consultarNfse(string $chave): RespostaSefin
    {
        $this->f->chamadas[] = 'consultar';

        return RespostaSefin::deJson(200, json_encode(['chaveAcesso' => $chave, 'nfseXmlGZipB64' => RespostaSefin::compactar($this->f->ultimaNfse)]), 'nfseXmlGZipB64');
    }

    public function registrarEvento(string $chave, string $pedidoAssinado): RespostaSefin
    {
        $this->f->chamadas[] = 'evento';
        $this->f->eventosEnviados[] = $pedidoAssinado;
        self::validarXsd($pedidoAssinado, 'pedRegEvento_v1.01.xsd', 2);

        $resposta = array_shift($this->f->respostas) ?? 'cancelado';
        if ($resposta === 'nao_aceita') {
            return RespostaSefin::deJson(400, json_encode(['erros' => [['codigo' => 'E0823', 'descricao' => 'Cancelamento não permitido']]]), 'eventoXmlGZipB64');
        }

        $evento = '<evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infEvento Id="EVT'.$chave.'1011010001"><nSeqEvento>1</nSeqEvento>'
            .$pedidoAssinado.'</infEvento></evento>';

        return RespostaSefin::deJson(201, json_encode(['eventoXmlGZipB64' => RespostaSefin::compactar($evento)]), 'eventoXmlGZipB64');
    }

    public function ultimaRequisicao(): ?string
    {
        return $this->ultima;
    }

    private function chave(): string
    {
        $n = str_pad((string) $this->f->proximoNumero++, 13, '0', STR_PAD_LEFT);

        return $this->empresa->codigo_municipio.'2'.'2'.$this->empresa->cnpj.$n.now()->format('ym').'123456789'.'0';
    }

    private function nfse(string $dps, string $chave): string
    {
        $dps = preg_replace('/^<\?xml[^>]*\?>/', '', $dps);
        $numero = (int) substr($chave, 23, 13);

        return '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS'.$chave.'">'
            .'<xLocEmi>Vitória</xLocEmi><xLocPrestacao>Vitória</xLocPrestacao><nNFSe>'.$numero.'</nNFSe><cLocIncid>3205309</cLocIncid>'
            .'<xLocIncid>Vitória</xLocIncid><xTribNac>Suporte técnico em informática.</xTribNac><verAplic>SefinNac</verAplic><ambGer>2</ambGer>'
            .'<tpEmis>1</tpEmis><procEmi>1</procEmi><cStat>100</cStat><dhProc>'.now('America/Sao_Paulo')->format('Y-m-d\TH:i:sP').'</dhProc><nDFSe>1</nDFSe>'
            .'<emit><CNPJ>'.$this->empresa->cnpj.'</CNPJ><xNome>'.e($this->empresa->razao_social).'</xNome></emit>'
            .'<valores><vLiq>'.$this->tag($dps, 'vServ').'</vLiq></valores>'.$dps.'</infNFSe></NFSe>';
    }

    private function tag(string $xml, string $nome): ?string
    {
        return preg_match("/<{$nome}>([^<]*)<\\/{$nome}>/", $xml, $m) ? $m[1] : null;
    }

    /** Valida no XSD do leiaute que a SEFIN aplica ao ambiente (produção restrita = NT 009). */
    public static function validarXsd(string $xml, string $arquivo, int $ambiente): void
    {
        // Os eventos não mudaram entre os pacotes: só o de 20/10/2026 está aqui
        $pasta = LeiauteDps::para($ambiente) === LeiauteDps::NT009 || str_starts_with($arquivo, 'pedRegEvento') ? '20261020' : '20260209';
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        libxml_use_internal_errors(true);
        $ok = $dom->schemaValidate(base_path("tests/Fixtures/nfse-xsd/{$pasta}/{$arquivo}"));
        $erros = array_map(fn ($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (! $ok) {
            throw new \RuntimeException("XML fora do XSD ({$pasta}): ".implode(' | ', $erros));
        }
    }
}
