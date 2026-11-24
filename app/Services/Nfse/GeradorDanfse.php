<?php

namespace App\Services\Nfse;

use App\Models\Empresa;
use App\Models\Nfse;
use App\Support\Formata;
use App\Support\TabelaServicosNacional;
use Com\Tecnick\Barcode\Barcode;
use Illuminate\Support\Facades\Storage;
use NFePHP\DA\Legacy\Pdf;

/**
 * DANFSe próprio (A4). A API de PDF do governo foi desativada (NT 008/2026): cada emissor gera o seu,
 * a partir do XML da NFS-e.
 */
class GeradorDanfse
{
    public const URL_CONSULTA = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

    private Pdf $pdf;

    private float $y;

    private const X = 10.0;

    private const L = 190.0;

    public function gerar(Nfse $nfse): string
    {
        $xml = (string) Storage::disk('local')->get($nfse->xml_path);
        $l = new LeitorNfse($xml);
        $empresa = $nfse->empresa;
        $nivel = error_reporting();
        error_reporting($nivel & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

        try {
            $this->pdf = new Pdf('P', 'mm', 'A4');
            $this->pdf->setMargins(self::X, 10, self::X);
            $this->pdf->setAutoPageBreak(true, 12);
            $this->pdf->addPage();
            $this->pdf->setLineWidth(0.2);
            $this->y = 10;

            // Marca d'água primeiro, bem clara, para ficar por baixo do texto
            if ($nfse->status === Nfse::CANCELADA) {
                $this->marcaDagua('CANCELADA');
            } elseif ((int) $nfse->ambiente === Empresa::AMBIENTE_HOMOLOGACAO) {
                $this->marcaDagua('SEM VALOR FISCAL');
            }

            $this->cabecalho($nfse, $l);
            $this->avisos($nfse);
            $this->prestador($nfse, $l, $empresa);
            $this->tomador($nfse);
            $this->servico($nfse, $l);
            $this->valores($nfse, $l, $empresa);
            $this->complementares($nfse, $empresa);

            return $this->pdf->output('', 'S');
        } finally {
            error_reporting($nivel);
        }
    }

    private function cabecalho(Nfse $nfse, LeitorNfse $l): void
    {
        $p = $this->pdf;
        $alt = 40;
        $p->rect(self::X, $this->y, self::L, $alt);

        $this->escrever(self::X + 3, $this->y + 3, 140, 'DANFSe', 15, 'B');
        $this->escrever(self::X + 3, $this->y + 10, 140, 'Documento Auxiliar da Nota Fiscal de Serviço Eletrônica', 9);
        $this->escrever(self::X + 3, $this->y + 14.5, 140, 'NFS-e Padrão Nacional - Município: '.($l->texto('//n:infNFSe/n:xLocEmi') ?? $nfse->empresa->municipio), 8);

        $campos = [
            ['Número da NFS-e', $nfse->numero_nfse ?? '-'],
            ['Competência', $nfse->competenciaTexto()],
            ['Emissão', $nfse->autorizada_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') ?? '-'],
            ['DPS (série/número)', ((int) $nfse->serie).' / '.$nfse->numero_dps],
        ];
        $x = self::X + 3;
        foreach ($campos as [$rotulo, $valor]) {
            $this->rotuloValor($x, $this->y + 20, 36, $rotulo, (string) $valor);
            $x += 37;
        }

        $this->escrever(self::X + 3, $this->y + 30, 20, 'Chave de acesso', 6.5, '', [90, 90, 90]);
        $this->escrever(self::X + 3, $this->y + 33.5, 145, trim(chunk_split((string) $nfse->chave, 5, ' ')), 9, 'B');

        if ($nfse->chave) {
            $png = (new Barcode())->getBarcodeObj('QRCODE,M', self::URL_CONSULTA.$nfse->chave, -4, -4, 'black', [-2, -2, -2, -2])
                ->setBackgroundColor('white')->getPngData();
            $p->image('data://text/plain;base64,'.base64_encode($png), self::X + self::L - 36, $this->y + 2, 33, 33, 'PNG');
            $this->escrever(self::X + self::L - 38, $this->y + 35.5, 37, 'Consulte em nfse.gov.br', 6, '', [90, 90, 90], 'C');
        }

        $this->y += $alt + 2;
    }

    private function avisos(Nfse $nfse): void
    {
        $avisos = [];
        if ((int) $nfse->ambiente === Empresa::AMBIENTE_HOMOLOGACAO) {
            $avisos[] = 'EMITIDA EM AMBIENTE DE HOMOLOGAÇÃO (TESTES) - SEM VALIDADE JURÍDICA';
        }
        if ($nfse->status === Nfse::CANCELADA) {
            $avisos[] = 'NFS-e CANCELADA'.($nfse->cancelada_em ? ' EM '.$nfse->cancelada_em->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '');
        }
        foreach ($avisos as $aviso) {
            $this->pdf->setFillColor(254, 226, 226);
            $this->pdf->rect(self::X, $this->y, self::L, 7, 'F');
            $this->escrever(self::X, $this->y + 1.6, self::L, $aviso, 9, 'B', [153, 27, 27], 'C');
            $this->y += 8;
        }
    }

    private function prestador(Nfse $nfse, LeitorNfse $l, Empresa $empresa): void
    {
        $nome = $l->texto('//n:infNFSe/n:emit/n:xNome') ?? $empresa->razao_social;
        $endereco = $this->juntar([
            $l->texto('//n:emit/n:enderNac/n:xLgr') ?? $empresa->logradouro,
            $l->texto('//n:emit/n:enderNac/n:nro') ?? $empresa->numero,
            $l->texto('//n:emit/n:enderNac/n:xBairro') ?? $empresa->bairro,
            $empresa->municipio.'/'.$empresa->uf,
            'CEP '.$this->cep($l->texto('//n:emit/n:enderNac/n:CEP') ?? $empresa->cep),
        ]);

        $this->secao('PRESTADOR DO SERVIÇO', [
            [['Nome / Razão social', $nome, 130], ['CNPJ', Formata::cnpj($empresa->cnpj), 60]],
            [['Endereço', $endereco, 130], ['Inscrição municipal', $empresa->inscricao_municipal ?: '-', 60]],
            [['E-mail', $l->texto('//n:emit/n:email') ?? ($empresa->email ?: '-'), 80], ['Telefone', $empresa->telefone ?: '-', 50],
                ['Regime', $empresa->ehMei() ? 'MEI' : 'Simples Nacional (ME/EPP)', 60]],
        ]);
    }

    private function tomador(Nfse $nfse): void
    {
        $t = $nfse->tomador_dados ?? [];
        if (empty($t['cpf_cnpj'])) {
            $this->secao('TOMADOR DO SERVIÇO', [[['Tomador', 'Não identificado', 190]]]);

            return;
        }

        $endereco = $this->juntar([
            $t['logradouro'] ?? null, $t['numero'] ?? null, $t['complemento'] ?? null, $t['bairro'] ?? null,
            filled($t['municipio'] ?? null) ? $t['municipio'].'/'.($t['uf'] ?? '') : null,
            filled($t['cep'] ?? null) ? 'CEP '.$this->cep($t['cep']) : null,
        ]);

        $this->secao('TOMADOR DO SERVIÇO', [
            [['Nome / Razão social', (string) ($t['razao_social'] ?? ''), 130], [strlen($t['cpf_cnpj']) === 11 ? 'CPF' : 'CNPJ', $nfse->tomadorDocumento(), 60]],
            [['Endereço', $endereco ?: '-', 130], ['E-mail', $t['email'] ?? '-', 60]],
        ]);
    }

    private function servico(Nfse $nfse, LeitorNfse $l): void
    {
        $codigo = TabelaServicosNacional::formatar($nfse->ctribnac).($nfse->ctribmun ? '.'.$nfse->ctribmun : '');
        $descricao = $l->texto('//n:infNFSe/n:xTribNac') ?? TabelaServicosNacional::descricao($nfse->ctribnac);

        $linhas = [
            [['Código de tributação nacional', $codigo.' - '.$descricao, 190]],
        ];
        if ($xTribMun = $l->texto('//n:infNFSe/n:xTribMun')) {
            $linhas[] = [['Tributação municipal', $xTribMun, 190]];
        }
        $linhas[] = [
            ['Local da prestação', $l->texto('//n:infNFSe/n:xLocPrestacao') ?? '-', 70],
            ['Município de incidência do ISS', $l->texto('//n:infNFSe/n:xLocIncid') ?? '-', 70],
            ['Código NBS', $nfse->cnbs ?: '-', 50],
        ];

        $this->secao('SERVIÇO PRESTADO', $linhas);

        // Discriminação (texto livre, várias linhas)
        $p = $this->pdf;
        $this->escrever(self::X + 2, $this->y, 100, 'Descrição do serviço', 6.5, '', [90, 90, 90]);
        $p->setFont('Arial', '', 8.5);
        $p->setXY(self::X + 2, $this->y + 3.5);
        $inicio = $this->y;
        $p->multicell(self::L - 4, 4, $this->iso($nfse->discriminacao));
        $this->y = max($p->getY(), $inicio + 8) + 2;
        $p->line(self::X, $this->y, self::X + self::L, $this->y);
        $this->y += 2;
    }

    private function valores(Nfse $nfse, LeitorNfse $l, Empresa $empresa): void
    {
        $v = $l->valores();
        $iss = $nfse->iss_retido ? 'Retido pelo tomador' : 'Não retido';

        $this->secao('TRIBUTAÇÃO E VALORES', [
            [
                ['Valor do serviço', Formata::moeda($nfse->valor_servico), 38],
                ['ISSQN', $iss, 38],
                ['Base de cálculo', $v['vBC'] ? Formata::moeda($v['vBC']) : '-', 38],
                ['Alíquota aplicada', $v['pAliqAplic'] ? number_format((float) $v['pAliqAplic'], 2, ',', '.').'%' : ($nfse->aliquota_iss ? number_format((float) $nfse->aliquota_iss, 2, ',', '.').'%' : '-'), 38],
                ['Valor do ISSQN', match (true) {
                    (bool) $v['vISSQN'] => Formata::moeda($v['vISSQN']),
                    $nfse->iss_retido => Formata::moeda($nfse->valor_iss_retido),
                    $empresa->ehMei() => 'Recolhido no DAS-SIMEI',
                    default => 'Recolhido no DAS',
                }, 38],
            ],
            [
                ['Retenção INSS', Formata::moeda($nfse->ret_inss), 38],
                ['Retenção IRRF', Formata::moeda($nfse->ret_irrf), 38],
                ['Retenção CSLL', Formata::moeda($nfse->ret_csll), 38],
                ['Retenção PIS', Formata::moeda($nfse->ret_pis), 38],
                ['Retenção COFINS', Formata::moeda($nfse->ret_cofins), 38],
            ],
        ]);

        $totalRet = $v['vTotalRet'] !== null ? (float) $v['vTotalRet'] : $nfse->totalRetencoesFederais() + (float) $nfse->valor_iss_retido;
        $liquido = $v['vLiq'] !== null ? (float) $v['vLiq'] : (float) $nfse->valor_liquido;

        $p = $this->pdf;
        $p->setFillColor(241, 245, 249);
        $p->rect(self::X, $this->y, self::L, 10, 'F');
        $this->rotuloValor(self::X + 3, $this->y + 1.5, 60, 'Total de retenções', Formata::moeda($totalRet));
        $this->escrever(self::X + 100, $this->y + 1.5, 87, 'VALOR LÍQUIDO DA NFS-e', 6.5, '', [90, 90, 90], 'R');
        $this->escrever(self::X + 100, $this->y + 4.5, 87, Formata::moeda($liquido), 12, 'B', [0, 0, 0], 'R');
        $this->y += 12;
    }

    private function complementares(Nfse $nfse, Empresa $empresa): void
    {
        $textos = [];
        if (! $empresa->ehMei() && $empresa->nfse_aliquota_simples !== null) {
            $aprox = round((float) $nfse->valor_servico * (float) $empresa->nfse_aliquota_simples / 100, 2);
            $textos[] = 'Valor aproximado dos tributos: '.Formata::moeda($aprox).' ('.number_format((float) $empresa->nfse_aliquota_simples, 2, ',', '.').'%) - Lei 12.741/2012.';
        }
        $textos[] = $empresa->ehMei()
            ? 'Emitida por Microempreendedor Individual (MEI).'
            : 'Documento emitido por ME ou EPP optante pelo Simples Nacional.';
        if (filled($nfse->informacoes_complementares)) {
            $textos[] = $nfse->informacoes_complementares;
        }
        if ($nfse->status === Nfse::CANCELADA && $nfse->cancelamento_motivo) {
            $textos[] = 'Motivo do cancelamento: '.$nfse->cancelamento_motivo;
        }

        $this->escrever(self::X, $this->y, 100, 'INFORMAÇÕES COMPLEMENTARES', 7.5, 'B');
        $this->y += 4.5;
        $p = $this->pdf;
        $p->setFont('Arial', '', 8);
        $p->setXY(self::X + 2, $this->y);
        $p->multicell(self::L - 4, 4, $this->iso(implode("\n", $textos)));
        $this->y = $p->getY() + 3;

        $p->setFont('Arial', '', 6.5);
        $p->setTextColor(110, 110, 110);
        $p->setXY(self::X, $this->y);
        $p->multicell(self::L, 3.2, $this->iso('Consulte a autenticidade desta NFS-e em https://www.nfse.gov.br/consultapublica com a chave de acesso. '
            .'DANFSe gerado por '.config('app.name', 'NotaPix').'.'), 0, 'C');
        $p->setTextColor(0, 0, 0);
    }

    /** Seção com título e linhas de campos [rótulo, valor, largura]. */
    private function secao(string $titulo, array $linhas): void
    {
        $p = $this->pdf;
        $p->setFillColor(226, 232, 240);
        $p->rect(self::X, $this->y, self::L, 5, 'F');
        $this->escrever(self::X + 2, $this->y + 0.8, self::L, $titulo, 7.5, 'B');
        $this->y += 6;

        foreach ($linhas as $campos) {
            $x = self::X + 2;
            foreach ($campos as [$rotulo, $valor, $largura]) {
                $this->rotuloValor($x, $this->y, $largura - 3, $rotulo, (string) $valor);
                $x += $largura;
            }
            $this->y += 9;
        }
        $p->line(self::X, $this->y - 1, self::X + self::L, $this->y - 1);
        $this->y += 1;
    }

    private function rotuloValor(float $x, float $y, float $w, string $rotulo, string $valor): void
    {
        $this->escrever($x, $y, $w, $rotulo, 6.5, '', [90, 90, 90]);
        $this->escrever($x, $y + 3.2, $w, $valor, 8.5, 'B', [0, 0, 0], 'L', true);
    }

    /** Texto numa linha; com $encolher, reduz a fonte até caber na largura. */
    private function escrever(float $x, float $y, float $w, string $texto, float $tam, string $estilo = '', array $cor = [0, 0, 0], string $alinhar = 'L', bool $encolher = false): void
    {
        $p = $this->pdf;
        $texto = $this->iso($texto);
        $p->setFont('Arial', $estilo, $tam);
        while ($encolher && $tam > 5 && $p->getStringWidth($texto) > $w) {
            $p->setFont('Arial', $estilo, $tam -= 0.5);
        }
        $p->setTextColor(...$cor);
        $p->setXY($x, $y);
        $p->cell($w, $tam * 0.42, $texto, 0, 0, $alinhar);
        $p->setTextColor(0, 0, 0);
    }

    private function marcaDagua(string $texto): void
    {
        $p = $this->pdf;
        $p->setFont('Arial', 'B', 54);
        $p->setTextColor(254, 226, 226);
        $largura = $p->getStringWidth($texto);
        $p->setXY((210 - $largura) / 2, 140);
        $p->cell($largura, 20, $texto, 0, 0, 'C');
        $p->setTextColor(0, 0, 0);
    }

    private function juntar(array $partes): string
    {
        return implode(', ', array_filter(array_map(fn ($p) => trim((string) $p), $partes), fn ($p) => $p !== '' && $p !== '/'));
    }

    private function cep(?string $cep): string
    {
        $c = preg_replace('/\D/', '', (string) $cep);

        return strlen($c) === 8 ? substr($c, 0, 5).'-'.substr($c, 5) : $c;
    }

    private function iso(?string $texto): string
    {
        return (string) mb_convert_encoding((string) $texto, 'ISO-8859-1', 'UTF-8');
    }
}
