<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use App\Models\Nfse;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;

/**
 * Monta o XML da DPS (Declaração de Prestação de Serviço) da NFS-e Nacional, na ordem exata do XSD.
 *
 * Regras que a SEFIN cobra (códigos de erro entre parênteses):
 *  - Prestador emitente (tpEmit=1): só CNPJ, IM (se o CNC tiver) e regime. Nome e endereço vêm do
 *    cadastro nacional e são proibidos na DPS (E0121/E0128).
 *  - ME/EPP: totTrib/pTotTribSN; MEI: indTotTrib=0 e nada de tributos federais (E0712/E0676).
 *  - regApTribSN só para ME/EPP (E0162).
 *  - pAliq só para ME/EPP com ISS pelo Simples e ISS retido pelo tomador (E0621/E0625).
 *  - Tomador identificado exige o nome; o endereço é tudo ou nada e vira obrigatório com ISS retido (E0237).
 *  - dhEmi no horário de Brasília e um pouco no passado (E0008).
 */
class MontadorDps
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    public const VERSAO = '1.01';

    public const VER_APLIC = 'NotaPix-1.0';

    private DOMDocument $dom;

    /** @return array{xml: string, id: string} */
    public function montar(Nfse $nfse, ?CarbonImmutable $agora = null): array
    {
        $empresa = $nfse->empresa;
        $agora ??= CarbonImmutable::now('America/Sao_Paulo');
        $leiaute = LeiauteDps::para((int) $nfse->ambiente, $agora);
        $nt009 = $leiaute === LeiauteDps::NT009;
        $mei = $empresa->ehMei();
        $opSimpNac = $mei ? 2 : 3;

        $id = self::idDps($empresa, (string) $nfse->serie, (int) $nfse->numero_dps);

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput = false;

        $raiz = $this->dom->createElementNS(self::NS, 'DPS');
        $raiz->setAttribute('versao', self::VERSAO);
        $this->dom->appendChild($raiz);

        $inf = $this->el($raiz, 'infDPS');
        $inf->setAttribute('Id', $id);

        $this->el($inf, 'tpAmb', (string) $nfse->ambiente);
        $this->el($inf, 'dhEmi', self::dhEmi($agora));
        $this->el($inf, 'verAplic', self::VER_APLIC);
        $this->el($inf, 'serie', (string) (int) $nfse->serie);
        $this->el($inf, 'nDPS', (string) $nfse->numero_dps);
        $this->el($inf, 'dCompet', self::dataCompetencia($nfse, $agora));
        $this->el($inf, 'tpEmit', '1');
        if ($nt009) {
            $this->el($inf, 'finNFSe', '0'); // NFS-e regular
        }
        $this->el($inf, 'cLocEmi', (string) $empresa->codigo_municipio);

        // Prestador
        $prest = $this->el($inf, 'prest');
        $this->el($prest, 'CNPJ', $empresa->cnpj);
        $im = preg_replace('/\D/', '', (string) $empresa->inscricao_municipal);
        if ($im !== '' && (int) $im > 0) {
            $this->el($prest, 'IM', $im);
        }
        $reg = $this->el($prest, 'regTrib');
        $this->el($reg, 'opSimpNac', (string) $opSimpNac);
        if ($opSimpNac === 3) {
            $this->el($reg, 'regApTribSN', (string) ($empresa->nfse_regime_apuracao ?: 1));
        }
        $this->el($reg, 'regEspTrib', '0');

        // Tomador
        $t = $nfse->tomador_dados ?? [];
        $doc = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) ($t['cpf_cnpj'] ?? '')));
        if ($doc !== '') {
            $toma = $this->el($inf, 'toma');
            $this->el($toma, strlen($doc) === 14 ? 'CNPJ' : 'CPF', $doc);
            $this->el($toma, 'xNome', self::texto($t['razao_social'] ?? '', 300));
            if (self::enderecoCompleto($t)) {
                $end = $this->el($toma, 'end');
                $endNac = $this->el($end, 'endNac');
                $this->el($endNac, 'cMun', (string) $t['codigo_municipio']);
                $this->el($endNac, 'CEP', preg_replace('/\D/', '', (string) $t['cep']));
                $this->el($end, 'xLgr', self::texto($t['logradouro'], 255));
                $this->el($end, 'nro', self::texto(filled($t['numero'] ?? null) ? $t['numero'] : 'S/N', 60));
                if (filled($t['complemento'] ?? null)) {
                    $this->el($end, 'xCpl', self::texto($t['complemento'], 156));
                }
                $this->el($end, 'xBairro', self::texto($t['bairro'], 60));
            }
            $fone = preg_replace('/\D/', '', (string) ($t['telefone'] ?? ''));
            if (strlen($fone) >= 6) {
                $this->el($toma, 'fone', substr($fone, 0, 20));
            }
            if (filled($t['email'] ?? null) && strlen(trim($t['email'])) <= 80) {
                $this->el($toma, 'email', trim($t['email']));
            }
        }

        // Serviço
        $serv = $this->el($inf, 'serv');
        $loc = $this->el($serv, 'locPrest');
        $this->el($loc, 'cLocPrestacao', (string) $nfse->municipio_prestacao);
        $cServ = $this->el($serv, 'cServ');
        $this->el($cServ, 'cTribNac', (string) $nfse->ctribnac);
        if (filled($nfse->ctribmun)) {
            $this->el($cServ, 'cTribMun', str_pad((string) $nfse->ctribmun, 3, '0', STR_PAD_LEFT));
        }
        $descricao = self::texto($nfse->discriminacao, 2000, manterQuebras: true);
        if (! $nt009) {
            $this->el($cServ, 'xDescServ', $descricao);
        }
        if (filled($nfse->cnbs)) {
            $this->el($cServ, 'cNBS', (string) $nfse->cnbs);
        }
        if ($nt009) {
            $this->el($cServ, 'xDescServ', $descricao); // a NT 009 passou a descrição para o fim do grupo
        }
        if (filled($nfse->informacoes_complementares)) {
            $info = $this->el($serv, 'infoCompl');
            $this->el($info, 'xInfComp', self::texto($nfse->informacoes_complementares, 2000, manterQuebras: true));
        }

        // Valores e tributos
        $valores = $this->el($inf, 'valores');
        $vsp = $this->el($valores, 'vServPrest');
        $this->el($vsp, 'vServ', self::dec($nfse->valor_servico));

        $trib = $this->el($valores, 'trib');
        $tribMun = $this->el($trib, 'tribMun');
        $this->el($tribMun, 'tribISSQN', '1'); // operação tributável
        $this->el($tribMun, 'tpRetISSQN', $nfse->iss_retido ? '2' : '1');
        if ($nfse->iss_retido && $opSimpNac === 3 && (int) ($empresa->nfse_regime_apuracao ?: 1) === 1) {
            $this->el($tribMun, 'pAliq', self::dec($nfse->aliquota_iss));
        }

        if (! $mei) {
            $this->tributosFederais($trib, $nfse);
        }

        $tot = $this->el($trib, 'totTrib');
        if ($mei) {
            $this->el($tot, 'indTotTrib', '0'); // MEI não informa tributos aproximados (Decreto 8.264/2014)
        } else {
            $this->el($tot, 'pTotTribSN', self::dec($empresa->nfse_aliquota_simples));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.$this->dom->saveXML($raiz);

        return ['xml' => $xml, 'id' => $id];
    }

    /** Retenções federais: INSS, IRRF, CSLL e, se houver, PIS/COFINS. */
    private function tributosFederais(DOMElement $trib, Nfse $nfse): void
    {
        $pis = (float) $nfse->ret_pis;
        $cofins = (float) $nfse->ret_cofins;
        $csll = (float) $nfse->ret_csll;

        if ($pis <= 0 && $cofins <= 0 && $csll <= 0 && (float) $nfse->ret_irrf <= 0 && (float) $nfse->ret_inss <= 0) {
            return;
        }

        $fed = $this->el($trib, 'tribFed');

        if ($pis > 0 || $cofins > 0) {
            $base = (float) $nfse->valor_servico;
            $pc = $this->el($fed, 'piscofins');
            $this->el($pc, 'CST', '01');
            $this->el($pc, 'vBCPisCofins', self::dec($base));
            $this->el($pc, 'pAliqPis', self::dec($base > 0 ? $pis / $base * 100 : 0));
            $this->el($pc, 'pAliqCofins', self::dec($base > 0 ? $cofins / $base * 100 : 0));
            $this->el($pc, 'vPis', self::dec($pis));
            $this->el($pc, 'vCofins', self::dec($cofins));
            $this->el($pc, 'tpRetPisCofins', match (true) {
                $pis > 0 && $cofins > 0 => $csll > 0 ? '3' : '4',
                $pis > 0 => $csll > 0 ? '9' : '5',
                default => $csll > 0 ? '7' : '6',
            });
        }

        if ((float) $nfse->ret_inss > 0) {
            $this->el($fed, 'vRetCP', self::dec($nfse->ret_inss));
        }
        if ((float) $nfse->ret_irrf > 0) {
            $this->el($fed, 'vRetIRRF', self::dec($nfse->ret_irrf));
        }
        if ($csll > 0) {
            $this->el($fed, 'vRetCSLL', self::dec($csll));
        }
    }

    /** "DPS" + município (7) + tipo da inscrição (1 CPF / 2 CNPJ) + inscrição (14) + série (5) + número (15). */
    public static function idDps(Empresa $empresa, string $serie, int $numero): string
    {
        $doc = strtoupper((string) $empresa->cnpj);

        return 'DPS'.str_pad((string) $empresa->codigo_municipio, 7, '0', STR_PAD_LEFT)
            .(strlen($doc) === 14 ? '2' : '1')
            .str_pad($doc, 14, '0', STR_PAD_LEFT)
            .str_pad((string) (int) $serie, 5, '0', STR_PAD_LEFT)
            .str_pad((string) $numero, 15, '0', STR_PAD_LEFT);
    }

    /** Agora, 5 segundos para trás (o relógio da SEFIN costuma estar um pouco atrás), sem voltar ao dia anterior. */
    public static function dhEmi(CarbonImmutable $agora): string
    {
        $agora = $agora->setTimezone('America/Sao_Paulo');
        $recuado = $agora->subSeconds(5);

        return ($recuado->isSameDay($agora) ? $recuado : $agora->startOfDay())->format('Y-m-d\TH:i:sP');
    }

    /** Data de competência: o último dia do mês escolhido, ou hoje se o mês ainda está correndo. */
    public static function dataCompetencia(Nfse $nfse, CarbonImmutable $agora): string
    {
        $hoje = $agora->setTimezone('America/Sao_Paulo')->startOfDay();
        $fim = CarbonImmutable::parse($nfse->competencia->format('Y-m-d'), 'America/Sao_Paulo')->endOfMonth()->startOfDay();

        return ($fim->greaterThan($hoje) ? $hoje : $fim)->format('Y-m-d');
    }

    public static function enderecoCompleto(array $t): bool
    {
        return strlen(preg_replace('/\D/', '', (string) ($t['codigo_municipio'] ?? ''))) === 7
            && strlen(preg_replace('/\D/', '', (string) ($t['cep'] ?? ''))) === 8
            && filled($t['logradouro'] ?? null)
            && filled($t['bairro'] ?? null);
    }

    private function el(DOMElement $pai, string $nome, ?string $valor = null): DOMElement
    {
        $el = $this->dom->createElement($nome);
        if ($valor !== null) {
            $el->appendChild($this->dom->createTextNode($valor));
        }
        $pai->appendChild($el);

        return $el;
    }

    private static function dec(mixed $valor): string
    {
        return number_format(round((float) $valor, 2), 2, '.', '');
    }

    /** Tira espaços duplicados e caracteres de controle, e corta no tamanho do campo. */
    private static function texto(?string $texto, int $max, bool $manterQuebras = false): string
    {
        $texto = (string) $texto;
        $texto = $manterQuebras
            ? preg_replace(["/\r\n?/", "/[ \t]+/", "/\n{3,}/"], ["\n", ' ', "\n\n"], $texto)
            : preg_replace('/\s+/', ' ', $texto);
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $texto);

        return mb_substr(trim((string) $texto), 0, $max);
    }
}
