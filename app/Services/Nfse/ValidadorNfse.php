<?php

namespace App\Services\Nfse;

use App\Models\Nfse;
use App\Support\TabelaServicosNacional;

/** Confere antes de enviar o que a SEFIN Nacional recusaria, com mensagens em linguagem simples. */
class ValidadorNfse
{
    public function problemas(Nfse $nfse): array
    {
        $empresa = $nfse->empresa;
        $p = [];

        if (! $nfse->podeEditar()) {
            return ['Esta NFS-e não pode mais ser enviada (situação: '.$nfse->statusLabel().').'];
        }
        if (! $empresa->dadosCadastraisCompletos()) {
            $p[] = 'Complete os dados da empresa (endereço, cidade e regime tributário).';
        }
        if (! $empresa->temCertificadoValido()) {
            $p[] = 'Envie um certificado digital A1 válido da empresa.';
        }
        if ($nfse->credito_reserva_id === null && ! $empresa->carteiraParaEmitir()) {
            $p[] = 'A empresa não tem créditos para emitir. Adquira créditos e tente novamente.';
        }
        if (! $empresa->ehMei() && $empresa->nfse_aliquota_simples === null) {
            $p[] = 'Informe em Dados da empresa → NFS-e o % aproximado de tributos do Simples (a alíquota efetiva da sua faixa; o contador informa).';
        }

        if (! TabelaServicosNacional::existe($nfse->ctribnac)) {
            $p[] = 'O código do serviço não está na lista nacional. Corrija o cadastro do serviço.';
        } elseif ($grupo = TabelaServicosNacional::grupoExigido($nfse->ctribnac)) {
            $p[] = $grupo === 'obra'
                ? 'Este código de serviço (construção civil) exige os dados da obra, que o NotaPix ainda não envia.'
                : 'Este código de serviço (item 12, eventos) exige os dados do evento, que o NotaPix ainda não envia.';
        }

        if ((float) $nfse->valor_servico <= 0) {
            $p[] = 'Informe o valor do serviço.';
        }
        if ((float) $nfse->valor_liquido < 0) {
            $p[] = 'As retenções são maiores que o valor do serviço.';
        }
        if (mb_strlen(trim((string) $nfse->discriminacao)) < 1) {
            $p[] = 'Descreva o serviço prestado.';
        }
        if ($nfse->competencia && $nfse->competencia->startOfMonth()->isAfter(now()->startOfMonth())) {
            $p[] = 'A competência não pode ser de um mês futuro.';
        }

        $t = $nfse->tomador_dados ?? [];
        if ($t) {
            if (($t['codigo_pais'] ?? '1058') !== '1058') {
                $p[] = 'Tomador do exterior ainda não é atendido na NFS-e do NotaPix.';
            }
            if (blank($t['razao_social'] ?? null)) {
                $p[] = 'O tomador precisa ter nome.';
            }
        }

        if ($nfse->iss_retido) {
            if ($empresa->ehMei()) {
                $p[] = 'MEI não sofre retenção de ISS. Desmarque "ISS retido pelo tomador".';
            }
            if (! $t) {
                $p[] = 'Com ISS retido, escolha o tomador (com CPF ou CNPJ).';
            } elseif (! MontadorDps::enderecoCompleto($t)) {
                $p[] = 'Com ISS retido, o endereço do tomador precisa estar completo (CEP, rua, bairro e cidade). Complete o cadastro da pessoa.';
            }
            $aliquota = (float) $nfse->aliquota_iss;
            if ((int) ($empresa->nfse_regime_apuracao ?: 1) === 1 && ($aliquota < 2 || $aliquota > 5)) {
                $p[] = 'Com ISS retido, informe em Dados da empresa → NFS-e a alíquota do ISS da sua faixa do Simples (entre 2% e 5%).';
            }
        }

        if ($empresa->ehMei() && $nfse->totalRetencoesFederais() > 0) {
            $p[] = 'MEI não informa retenções federais na NFS-e.';
        }

        return $p;
    }
}
