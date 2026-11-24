<?php

namespace Tests\Concerns;

use App\Models\Empresa;
use App\Services\Nfe\ClienteSefaz;
use App\Services\Nfe\ClienteSefazNfephp;
use App\Services\Nfe\FabricaClienteSefaz;

/**
 * SEFAZ falsa para os testes: assina e valida o XML de verdade (NFePHP + XSD oficial),
 * mas responde com mensagens prontas em vez de chamar a internet.
 */
trait SefazFalsa
{
    /** Respostas na ordem em que serão usadas: 'autorizada', 'rejeitada', 'duplicidade', 'erro', 'nao_encontrada', 'processando'. */
    protected function usarSefazFalsa(string ...$respostas): FabricaClienteSefazFalsa
    {
        $fabrica = new FabricaClienteSefazFalsa($respostas);
        $this->app->instance(FabricaClienteSefaz::class, $fabrica);

        return $fabrica;
    }
}

class FabricaClienteSefazFalsa extends FabricaClienteSefaz
{
    public array $chamadas = [];

    /** Resposta da consulta cadastro (null = "não cadastrado como contribuinte"). */
    public ?string $cadastro = null;

    public function __construct(public array $respostas)
    {
    }

    /** Modelos pedidos (55 ou 65), na ordem. */
    public array $modelos = [];

    public function para(Empresa $empresa, int $modelo = 55): ClienteSefaz
    {
        $this->modelos[] = $modelo;

        return new ClienteSefazFalso($empresa, $this, $modelo);
    }
}

class ClienteSefazFalso implements ClienteSefaz
{
    private ClienteSefazNfephp $real;

    private string $ultimoAssinado = '';

    public function __construct(Empresa $empresa, private FabricaClienteSefazFalsa $fabrica, int $modelo = 55)
    {
        $this->real = new ClienteSefazNfephp($empresa, $modelo);
    }

    public function assinar(string $xml): string
    {
        return $this->ultimoAssinado = $this->real->assinar($xml);
    }

    public function autorizar(string $xmlAssinado, string $idLote): string
    {
        $this->fabrica->chamadas[] = 'autorizar';

        return $this->responder($xmlAssinado);
    }

    public function consultarChave(string $chave): string
    {
        $this->fabrica->chamadas[] = 'consultar';

        // A consulta precisa do XML assinado: pega o que foi guardado na nota
        $nota = \App\Models\Nota::where('chave', $chave)->firstOrFail();
        $xml = \Illuminate\Support\Facades\Storage::disk('local')->get(
            "notas/{$nota->empresa_id}/".$nota->created_at->format('Y/m')."/{$chave}-nfe.xml"
        );

        return $this->responder((string) $xml, consulta: true);
    }

    private string $ultimaRequisicao = '<enviNFe>...</enviNFe>';

    public function cancelar(string $chave, string $justificativa, string $protocolo): string
    {
        $this->fabrica->chamadas[] = 'cancelar';

        return $this->evento($chave, 110111, 1, "<descEvento>Cancelamento</descEvento><nProt>$protocolo</nProt><xJust>$justificativa</xJust>");
    }

    public function cartaCorrecao(string $chave, string $texto, int $sequencia): string
    {
        $this->fabrica->chamadas[] = 'cce';

        return $this->evento($chave, 110110, $sequencia, "<descEvento>Carta de Correcao</descEvento><xCorrecao>$texto</xCorrecao>"
            .'<xCondUso>A Carta de Correcao e disciplinada pelo paragrafo 1o-A do art. 7o do Convenio S/N, de 15 de dezembro de 1970</xCondUso>');
    }

    /** Monta o pedido (como a NFePHP faria) e a resposta da SEFAZ para um evento. */
    private function evento(string $chave, int $tipo, int $seq, string $detalhe): string
    {
        $resposta = array_shift($this->fabrica->respostas) ?? 'registrado';
        if ($resposta === 'erro') {
            throw new \RuntimeException('Tempo de conexão esgotado');
        }

        $ns = 'xmlns="http://www.portalfiscal.inf.br/nfe"';
        $id = 'ID'.$tipo.$chave.str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
        $cnpj = substr($chave, 6, 14);
        $agora = now()->format('Y-m-d\TH:i:sP');
        $this->ultimaRequisicao = "<envEvento $ns versao=\"1.00\"><idLote>123</idLote><evento $ns versao=\"1.00\"><infEvento Id=\"$id\">"
            ."<cOrgao>32</cOrgao><tpAmb>2</tpAmb><CNPJ>$cnpj</CNPJ><chNFe>$chave</chNFe><dhEvento>$agora</dhEvento>"
            ."<tpEvento>$tipo</tpEvento><nSeqEvento>$seq</nSeqEvento><verEvento>1.00</verEvento><detEvento versao=\"1.00\">$detalhe</detEvento>"
            .'</infEvento></evento></envEvento>';

        [$cStat, $motivo, $prot] = match ($resposta) {
            'registrado' => [135, 'Evento registrado e vinculado a NF-e', '<nProt>332260000099999</nProt>'],
            'prazo' => [501, 'Rejeicao: Prazo de cancelamento superior ao previsto na Legislacao', ''],
            default => [573, 'Rejeicao: Duplicidade de evento', ''],
        };

        return "<retEnvEvento $ns versao=\"1.00\"><idLote>123</idLote><tpAmb>2</tpAmb><verAplic>SVRS</verAplic><cOrgao>32</cOrgao>"
            .'<cStat>128</cStat><xMotivo>Lote de evento processado</xMotivo>'
            ."<retEvento versao=\"1.00\"><infEvento><tpAmb>2</tpAmb><verAplic>SVRS</verAplic><cOrgao>32</cOrgao><cStat>$cStat</cStat>"
            ."<xMotivo>$motivo</xMotivo><chNFe>$chave</chNFe><tpEvento>$tipo</tpEvento><nSeqEvento>$seq</nSeqEvento>"
            ."<dhRegEvento>$agora</dhRegEvento>$prot</infEvento></retEvento></retEnvEvento>";
    }

    public function consultarCadastro(string $uf, string $cnpj = '', string $ie = ''): string
    {
        $this->fabrica->chamadas[] = 'cadastro';

        return $this->fabrica->cadastro ?? '<retConsCad xmlns="http://www.portalfiscal.inf.br/nfe" versao="2.00"><infCons>'
            .'<cStat>259</cStat><xMotivo>Rejeicao: CNPJ da consulta nao cadastrado como contribuinte na UF</xMotivo></infCons></retConsCad>';
    }

    public function ultimaRequisicao(): string
    {
        return $this->ultimaRequisicao;
    }

    private function responder(string $xml, bool $consulta = false): string
    {
        $tipo = array_shift($this->fabrica->respostas) ?? 'autorizada';

        if ($tipo === 'erro') {
            throw new \RuntimeException('Tempo de conexão esgotado');
        }

        preg_match('/Id="NFe(\d{44})"/', $xml, $m);
        $chave = $m[1] ?? str_repeat('0', 44);
        preg_match('/<DigestValue>([^<]+)<\/DigestValue>/', $xml, $d);
        $digest = $d[1] ?? '';

        [$cStat, $motivo] = match ($tipo) {
            'autorizada' => [100, 'Autorizado o uso da NF-e'],
            'rejeitada' => [539, 'Rejeição: Duplicidade de NF-e com diferença na Chave de Acesso'],
            'duplicidade' => [204, 'Rejeição: Duplicidade de NF-e'],
            'denegada' => [301, 'Uso Denegado: Irregularidade fiscal do emitente'],
            default => [0, ''],
        };

        $ns = 'xmlns="http://www.portalfiscal.inf.br/nfe"';

        if ($tipo === 'nao_encontrada') {
            return "<retConsSitNFe $ns versao=\"4.00\"><tpAmb>2</tpAmb><cStat>217</cStat><xMotivo>Rejeição: NF-e não consta na base de dados da SEFAZ</xMotivo></retConsSitNFe>";
        }
        if ($tipo === 'processando') {
            return "<retEnviNFe $ns versao=\"4.00\"><tpAmb>2</tpAmb><cStat>103</cStat><xMotivo>Lote recebido com sucesso</xMotivo><infRec><nRec>321000012345678</nRec></infRec></retEnviNFe>";
        }

        $prot = "<protNFe versao=\"4.00\"><infProt><tpAmb>2</tpAmb><verAplic>SVRS202610</verAplic><chNFe>$chave</chNFe>"
            .'<dhRecbto>'.now()->format('Y-m-d\TH:i:sP').'</dhRecbto>'
            .($cStat === 100 || $cStat === 301 ? '<nProt>332260000012345</nProt>' : '')
            ."<digVal>$digest</digVal><cStat>$cStat</cStat><xMotivo>$motivo</xMotivo></infProt></protNFe>";

        return $consulta
            ? "<retConsSitNFe $ns versao=\"4.00\"><tpAmb>2</tpAmb><cStat>$cStat</cStat><xMotivo>$motivo</xMotivo><chNFe>$chave</chNFe>$prot</retConsSitNFe>"
            : "<retEnviNFe $ns versao=\"4.00\"><tpAmb>2</tpAmb><cStat>104</cStat><xMotivo>Lote processado</xMotivo>$prot</retEnviNFe>";
    }
}
