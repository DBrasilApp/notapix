<?php

namespace Tests\Unit;

use App\Services\Nfe\CadastroSefaz;
use PHPUnit\Framework\TestCase;

class CadastroSefazTest extends TestCase
{
    public function test_le_contribuinte_credenciado(): void
    {
        $xml = '<retConsCad xmlns="http://www.portalfiscal.inf.br/nfe" versao="2.00"><infCons>'
            .'<verAplic>SVRS</verAplic><cStat>111</cStat><xMotivo>Consulta cadastro com uma ocorrencia</xMotivo>'
            .'<UF>ES</UF><CNPJ>26479231000113</CNPJ><dhCons>2026-11-02T08:00:00-03:00</dhCons><cUF>32</cUF>'
            .'<infCad><IE>082123456</IE><CNPJ>26479231000113</CNPJ><UF>ES</UF><cSit>1</cSit>'
            .'<indCredNFe>0</indCredNFe><indCredCTe>4</indCredCTe><xNome>EMPRESA TESTE LTDA</xNome>'
            .'<xRegApur>SIMPLES NACIONAL</xRegApur><CNAE>5611201</CNAE><dIniAtiv>2016-01-01</dIniAtiv></infCad>'
            .'</infCons></retConsCad>';

        $r = CadastroSefaz::interpretar($xml);

        $this->assertSame(111, $r['codigo']);
        $this->assertCount(1, $r['contribuintes']);
        $this->assertSame('082123456', $r['contribuintes'][0]['ie']);
        $this->assertSame('1', $r['contribuintes'][0]['situacao']);
        $this->assertSame('0', $r['contribuintes'][0]['cred_nfe']);
    }

    public function test_resposta_vazia(): void
    {
        $this->assertSame(0, CadastroSefaz::interpretar('')['codigo']);
    }
}
