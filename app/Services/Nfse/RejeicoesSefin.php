<?php

namespace App\Services\Nfse;

use App\Models\Nfse;

/**
 * Traduz as recusas mais comuns da SEFIN Nacional (NFS-e) para linguagem simples,
 * no mesmo formato das rejeições da NF-e (componente x-rejeicao).
 */
final class RejeicoesSefin
{
    /** código => [o que aconteceu, o que fazer, destino do botão: dados|certificado|tomador|servico|corrigir|null] */
    private const TABELA = [
        'E0008' => ['A data de emissão ficou à frente do relógio da SEFIN.', 'Envie de novo: a data e a hora são atualizadas a cada envio.', 'corrigir'],
        'E0037' => ['O município da empresa não emite pelo Sistema Nacional (sem convênio).', 'Confirme com a prefeitura se o município já aderiu à NFS-e Nacional. Em homologação, nem todo município está disponível.', null],
        'E0116' => ['A prefeitura exige a inscrição municipal da empresa na nota.', 'Informe a inscrição municipal em Dados da empresa → NFS-e (a mesma que aparece nas notas emitidas no portal).', 'dados'],
        'E0120' => ['A prefeitura não usa inscrição municipal para esta empresa no Sistema Nacional.', 'Apague a inscrição municipal em Dados da empresa → NFS-e e envie de novo.', 'dados'],
        'E0121' => ['O nome do prestador não pode ir na nota.', 'Fale com o suporte do NotaPix.', null],
        'E0128' => ['O endereço do prestador não pode ir na nota.', 'Fale com o suporte do NotaPix.', null],
        'E0162' => ['O regime de apuração do Simples não pode ser informado para este regime.', 'Confira o regime tributário (Simples Nacional ou MEI) em Dados da empresa.', 'dados'],
        'E0204' => ['Com ISS retido, o tomador precisa ter CPF ou CNPJ.', 'Escolha um tomador cadastrado ou desmarque "ISS retido pelo tomador".', 'corrigir'],
        'E0237' => ['Com ISS retido, o endereço completo do tomador é obrigatório.', 'Complete o endereço do tomador (CEP, rua, bairro e cidade) no cadastro de Pessoas.', 'tomador'],
        'E0240' => ['O CEP do tomador não existe ou não é da cidade dele.', 'Corrija o CEP no cadastro do tomador. Cuidado com o CEP geral da cidade (terminado em 000).', 'tomador'],
        'E0312' => ['A prefeitura não usa este código de serviço sozinho: falta o código municipal (desdobro).', 'Abra o serviço e informe o código municipal de 3 dígitos (o final do código usado no portal, ex.: 01.07.01.001 → 001).', 'servico'],
        'E0315' => ['Este município não usa código municipal para este serviço.', 'Abra o serviço e deixe o código municipal em branco.', 'servico'],
        'E0316' => ['O código NBS do serviço não existe.', 'Abra o serviço e corrija o código NBS (ou deixe em branco).', 'servico'],
        'E0390' => ['Este código de serviço exige os dados do evento.', 'Este tipo de serviço (item 12) ainda não é emitido pelo NotaPix.', null],
        'E0583' => ['MEI não sofre retenção de ISS.', 'Desmarque "ISS retido pelo tomador".', 'corrigir'],
        'E0617' => ['A alíquota do ISS não pode ser informada.', 'Fale com o suporte do NotaPix.', null],
        'E0621' => ['Com ISS retido, a alíquota do ISS é obrigatória.', 'Informe a alíquota do ISS da sua faixa do Simples em Dados da empresa → NFS-e.', 'dados'],
        'E0625' => ['A alíquota do ISS só pode ir na nota quando o ISS é retido.', 'Fale com o suporte do NotaPix.', null],
        'E0628' => ['A alíquota do ISS informada está abaixo do mínimo (2%).', 'Corrija a alíquota do ISS em Dados da empresa → NFS-e.', 'dados'],
        'E0676' => ['MEI não informa tributos federais na NFS-e.', 'Retire as retenções federais da nota.', 'corrigir'],
        'E0698' => ['Falta o tipo de retenção do PIS/COFINS.', 'Fale com o suporte do NotaPix.', null],
        'E0712' => ['O valor aproximado dos tributos foi informado no formato errado para ME/EPP.', 'Confira o regime tributário em Dados da empresa.', 'dados'],
        'E0823' => ['Esta prefeitura não aceita o cancelamento direto pelo contribuinte.', 'Peça o cancelamento (análise fiscal) no portal da NFS-e Nacional ou na prefeitura.', null],
        'E0840' => ['Já existe um pedido de cancelamento para esta NFS-e.', 'Confira a situação da nota no portal da NFS-e Nacional.', null],
        'E1229' => ['O arquivo foi enviado com codificação diferente de UTF-8.', 'Fale com o suporte do NotaPix.', null],
        'E1235' => ['A SEFIN recusou o formato da DPS.', 'Fale com o suporte do NotaPix informando a data e a hora do envio.', null],
    ];

    /** @return array{titulo: string, acao: string, botao: ?string, url: ?string}|null */
    public static function explicar(?string $codigo, ?Nfse $nfse = null): ?array
    {
        $codigo = strtoupper(trim((string) $codigo));
        if (! isset(self::TABELA[$codigo])) {
            return null;
        }

        [$titulo, $acao, $destino] = self::TABELA[$codigo];
        [$botao, $url] = match ($destino) {
            'dados' => ['Abrir Dados da empresa', route('empresa.dados')],
            'certificado' => ['Enviar certificado', route('empresa.certificado')],
            'tomador' => $nfse?->destinatario_id
                ? ['Corrigir cadastro do tomador', route('empresa.pessoas.edit', $nfse->destinatario_id)]
                : ['Abrir Pessoas', route('empresa.pessoas.index')],
            'servico' => $nfse?->servico_id
                ? ['Abrir o serviço', route('empresa.servicos.edit', $nfse->servico_id)]
                : ['Abrir Serviços', route('empresa.servicos.index')],
            'corrigir' => $nfse && $nfse->podeEditar() ? ['Corrigir a nota', route('empresa.nfse.edit', $nfse->id)] : [null, null],
            default => [null, null],
        };

        return ['titulo' => $titulo, 'acao' => $acao, 'botao' => $botao, 'url' => $url];
    }

    public static function frase(?string $codigo): ?string
    {
        $e = self::explicar($codigo);

        return $e ? $e['titulo'].' '.$e['acao'] : null;
    }
}
