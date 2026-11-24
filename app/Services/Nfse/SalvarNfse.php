<?php

namespace App\Services\Nfse;

use App\Models\Destinatario;
use App\Models\Empresa;
use App\Models\Nfse;
use App\Models\Servico;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Grava a NFS-e (rascunho) a partir dos dados da tela, calculando retenções e valor líquido. */
class SalvarNfse
{
    /**
     * @param  array{servico_id: int, destinatario_id: ?int, competencia: string, discriminacao: string,
     *     informacoes_complementares?: ?string, valor_servico: float|string, iss_retido?: bool,
     *     ret_inss?: mixed, ret_irrf?: mixed, ret_csll?: mixed, ret_pis?: mixed, ret_cofins?: mixed,
     *     contrato_id?: ?int, lote?: ?string}  $dados
     */
    public function salvar(Empresa $empresa, ?User $usuario, array $dados, ?Nfse $nfse = null): Nfse
    {
        /** @var Servico $servico */
        $servico = $empresa->servicos()->findOrFail($dados['servico_id']);
        $tomador = ! empty($dados['destinatario_id']) ? $empresa->destinatarios()->findOrFail($dados['destinatario_id']) : null;

        $valor = round((float) $dados['valor_servico'], 2);
        $retido = (bool) ($dados['iss_retido'] ?? false);
        $aliquota = $retido ? (float) $empresa->nfse_aliquota_iss : null;
        $issRetido = $retido ? round($valor * (float) $aliquota / 100, 2) : 0.0;

        $retencoes = [];
        foreach (array_keys(Nfse::RETENCOES) as $campo) {
            // MEI não informa tributos federais
            $retencoes[$campo] = $empresa->ehMei() ? 0.0 : round(max(0, (float) ($dados[$campo] ?? 0)), 2);
        }

        $nfse ??= new Nfse(['empresa_id' => $empresa->id, 'status' => Nfse::RASCUNHO]);
        $nfse->fill([
            'user_id' => $usuario?->id ?? $nfse->user_id,
            'servico_id' => $servico->id,
            'destinatario_id' => $tomador?->id,
            'contrato_id' => $dados['contrato_id'] ?? $nfse->contrato_id,
            'lote' => $dados['lote'] ?? $nfse->lote,
            'tomador_dados' => $tomador ? self::dadosTomador($tomador) : null,
            'ambiente' => $nfse->numero_dps ? $nfse->ambiente : $empresa->ambiente,
            'competencia' => CarbonImmutable::createFromFormat('Y-m-d', substr($dados['competencia'], 0, 7).'-01')->toDateString(),
            'ctribnac' => $servico->ctribnac,
            'ctribmun' => $servico->ctribmun,
            'cnbs' => $servico->cnbs,
            'municipio_prestacao' => (string) $empresa->codigo_municipio,
            'discriminacao' => self::trocarMes(trim((string) $dados['discriminacao']), $dados['competencia']),
            'informacoes_complementares' => filled($dados['informacoes_complementares'] ?? null) ? trim($dados['informacoes_complementares']) : null,
            'valor_servico' => $valor,
            'iss_retido' => $retido,
            'aliquota_iss' => $aliquota,
            'valor_iss_retido' => $issRetido,
            'valor_liquido' => round($valor - $issRetido - array_sum($retencoes), 2),
        ] + $retencoes);
        $nfse->save();

        return $nfse;
    }

    /** {mes} no texto do serviço vira o mês da competência (ex.: 11/2026). */
    public static function trocarMes(string $texto, string $competencia): string
    {
        $mes = substr($competencia, 5, 2).'/'.substr($competencia, 0, 4);

        return str_ireplace(['{mes}', '{mês}'], $mes, $texto);
    }

    /** Cópia do tomador no momento da emissão (o cadastro pode mudar depois). */
    public static function dadosTomador(Destinatario $t): array
    {
        return $t->only([
            'cpf_cnpj', 'razao_social', 'email', 'telefone', 'logradouro', 'numero', 'complemento',
            'bairro', 'codigo_municipio', 'municipio', 'uf', 'cep', 'codigo_pais',
        ]);
    }
}
