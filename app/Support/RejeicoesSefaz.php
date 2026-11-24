<?php

namespace App\Support;

use App\Models\Nota;

/**
 * Traduz as rejeições mais comuns da SEFAZ para linguagem simples:
 * o que aconteceu, o que fazer e, quando der, um botão que leva direto ao lugar de corrigir.
 */
final class RejeicoesSefaz
{
    /**
     * código => [o que aconteceu, o que fazer, destino do botão]
     * Destinos: dados, certificado, pessoa, produtos, corrigir, suporte (null = sem botão).
     */
    private const TABELA = [
        // Cadastro e credenciamento do emitente
        203 => ['A empresa não está habilitada na SEFAZ para emitir NF-e.', 'Peça ao seu contador o credenciamento da empresa para NF-e (modelo 55) na SEFAZ. Em testes (homologação), a habilitação precisa existir também no ambiente de homologação.', null],
        781 => ['A empresa não está habilitada na SEFAZ para emitir este tipo de nota (NF-e/NFC-e).', 'Peça ao seu contador o credenciamento da empresa na SEFAZ para NFC-e (cupom fiscal). Em testes (homologação), a habilitação precisa existir também no ambiente de homologação.', null],
        207 => ['O CNPJ da empresa está inválido.', 'Confira o CNPJ em Dados da empresa.', 'dados'],
        209 => ['A inscrição estadual da empresa está inválida.', 'Confira a inscrição estadual em Dados da empresa (no ES ela tem 9 números e costuma começar com 0).', 'dados'],
        230 => ['A inscrição estadual da empresa não está cadastrada na SEFAZ.', 'Confira a inscrição estadual em Dados da empresa. Se estiver certa, fale com o seu contador.', 'dados'],
        231 => ['A inscrição estadual não pertence a este CNPJ na SEFAZ.', 'Confira a inscrição estadual em Dados da empresa: ela precisa ser a do mesmo CNPJ.', 'dados'],
        245 => ['O CNPJ da empresa não está cadastrado na SEFAZ deste estado.', 'Confira o CNPJ e a UF em Dados da empresa. Se estiverem certos, fale com o seu contador.', 'dados'],
        240 => ['A SEFAZ apontou irregularidade fiscal da empresa.', 'Fale com o seu contador para regularizar a situação da empresa na SEFAZ.', null],
        301 => ['Uso denegado: a empresa está com irregularidade fiscal na SEFAZ.', 'A nota foi registrada como denegada e o número não pode ser reutilizado. Fale com o seu contador para regularizar a empresa.', null],
        302 => ['Uso denegado: o destinatário está com irregularidade fiscal na SEFAZ.', 'A nota foi registrada como denegada e o número não pode ser reutilizado. O cliente precisa regularizar a inscrição estadual dele.', null],
        303 => ['Uso denegado: o destinatário não está habilitado a operar no estado dele.', 'A nota foi registrada como denegada. Confirme a situação cadastral do cliente.', null],
        590 => ['O regime tributário da empresa não confere com os impostos da nota.', 'Confira o regime tributário (Simples Nacional ou MEI) em Dados da empresa.', 'dados'],
        591 => ['A SEFAZ não reconhece a empresa como optante do Simples Nacional.', 'Confira o regime tributário em Dados da empresa e, se preciso, confirme com o seu contador.', 'dados'],

        // Certificado digital
        213 => ['O certificado digital é de outro CNPJ.', 'Envie o certificado A1 da própria empresa (mesma raiz de CNPJ).', 'certificado'],
        280 => ['O certificado digital foi recusado pela SEFAZ.', 'Envie de novo o certificado A1 da empresa (arquivo .pfx e senha).', 'certificado'],
        281 => ['O certificado digital está vencido.', 'Renove o certificado A1 com a certificadora e envie o novo arquivo.', 'certificado'],
        282 => ['O certificado digital não tem o CNPJ da empresa.', 'Envie o certificado A1 de pessoa jurídica (e-CNPJ) da empresa.', 'certificado'],
        283 => ['O certificado digital não é reconhecido pela SEFAZ.', 'Use um certificado A1 de uma certificadora ICP-Brasil e envie de novo.', 'certificado'],
        284 => ['O certificado digital foi revogado.', 'Emita um novo certificado A1 com a certificadora e envie o arquivo.', 'certificado'],
        285 => ['A SEFAZ não conseguiu conferir o certificado digital.', 'Tente de novo em alguns minutos. Se repetir, envie o certificado outra vez.', 'certificado'],

        // Destinatário
        208 => ['O CNPJ do destinatário está inválido.', 'Corrija o CNPJ no cadastro da pessoa.', 'pessoa'],
        237 => ['O CPF do destinatário está inválido.', 'Corrija o CPF no cadastro da pessoa (ou na venda, no caso de NFC-e).', 'pessoa'],
        210 => ['A inscrição estadual do destinatário está inválida.', 'Corrija a inscrição estadual no cadastro da pessoa.', 'pessoa'],
        232 => ['O destinatário está marcado como contribuinte, mas sem inscrição estadual.', 'No cadastro da pessoa, informe a inscrição estadual ou marque "Não contribuinte".', 'pessoa'],
        233 => ['A inscrição estadual do destinatário não está cadastrada na SEFAZ.', 'Confira a inscrição estadual com o cliente. Se ele não tiver, marque "Não contribuinte" no cadastro.', 'pessoa'],
        234 => ['A inscrição estadual do destinatário não pertence ao CNPJ dele.', 'Confira a inscrição estadual com o cliente e corrija no cadastro da pessoa.', 'pessoa'],
        805 => ['O estado do destinatário não aceita cliente "isento de inscrição estadual".', 'No cadastro da pessoa, marque "Não contribuinte" ou informe a inscrição estadual.', 'pessoa'],
        694 => ['Venda para consumidor final não contribuinte de outro estado (DIFAL).', 'Este tipo de venda ainda não é feito pelo NotaPix. Fale com o seu contador.', null],

        // Produtos e valores
        778 => ['O NCM de algum produto não existe na tabela oficial.', 'Abra o produto e escolha o NCM pela pesquisa.', 'produtos'],
        806 => ['Produto com substituição tributária sem o CEST.', 'Informe o CEST no cadastro do produto.', 'produtos'],
        528 => ['O valor do ICMS de algum item não confere com a base e a alíquota.', 'Corrija os impostos devolvidos do item (base x alíquota = valor).', 'corrigir'],
        629 => ['O valor de algum produto não confere com quantidade x preço.', 'Abra a nota em Corrigir e salve de novo. Se repetir, fale com o suporte.', 'corrigir'],
        610 => ['O total da nota não confere com a soma dos itens.', 'Abra a nota em Corrigir e salve de novo. Se repetir, fale com o suporte.', 'corrigir'],

        // Numeração e duplicidade
        206 => ['Este número de nota já foi inutilizado na SEFAZ.', 'Em Dados da empresa, informe como "última nota" um número maior, para pular este.', 'dados'],
        539 => ['Já existe na SEFAZ outra nota com este número (provavelmente emitida por outro sistema).', 'Em Dados da empresa, informe o número da última nota realmente emitida e envie de novo.', 'dados'],
        228 => ['A data de emissão está muito antiga.', 'Envie de novo: o NotaPix usa a data e hora atuais.', 'corrigir'],
        704 => ['A NFC-e foi enviada com a data e hora de emissão atrasadas.', 'Envie de novo: a data e hora são atualizadas no envio.', 'corrigir'],

        // Devolução e referências
        267 => ['A nota de compra informada não foi encontrada na SEFAZ.', 'Confira a chave de acesso. Em testes (homologação), a nota de compra precisa ter sido emitida também em homologação: uma nota real de produção não existe lá.', 'corrigir'],
        321 => ['A devolução está sem a nota de compra referenciada.', 'Informe a chave de acesso da NF-e de compra (nota em papel não é mais aceita).', 'corrigir'],

        // NFC-e: CSC e QR Code
        462 => ['O ID do CSC da NFC-e não está cadastrado na SEFAZ.', 'Confira o ID do CSC em Dados da empresa → NFC-e.', 'dados'],
        463 => ['O CSC da NFC-e foi revogado na SEFAZ.', 'Gere um novo CSC na SEFAZ e informe em Dados da empresa → NFC-e.', 'dados'],
        464 => ['O CSC da NFC-e não confere com o cadastrado na SEFAZ.', 'Confira o CSC (e o ID) do ambiente certo em Dados da empresa → NFC-e.', 'dados'],

        // Cancelamento e eventos
        220 => ['O prazo para cancelar terminou.', 'Depois do prazo, o cancelamento só pode ser pedido diretamente à SEFAZ (extemporâneo). Fale com o seu contador.', null],
        501 => ['O prazo para cancelar terminou.', 'Depois do prazo, o cancelamento só pode ser pedido diretamente à SEFAZ (extemporâneo). Fale com o seu contador.', null],
        218 => ['Esta nota já está cancelada na SEFAZ.', 'Não é preciso fazer nada.', null],
        573 => ['A SEFAZ já tinha recebido este mesmo pedido.', 'Atualize a página: o pedido anterior provavelmente já foi registrado.', null],

        // SEFAZ fora do ar
        108 => ['O serviço da SEFAZ está paralisado no momento.', 'Tente de novo em alguns minutos.', null],
        109 => ['O serviço da SEFAZ está fora do ar no momento.', 'Tente de novo mais tarde.', null],
        656 => ['A SEFAZ bloqueou temporariamente o envio por excesso de tentativas.', 'Aguarde cerca de 1 hora antes de enviar de novo.', null],
        999 => ['Erro interno na SEFAZ.', 'Tente de novo em alguns minutos.', null],

        // Erros técnicos
        215 => ['A SEFAZ recusou o formato do arquivo da nota.', 'Fale com o suporte do NotaPix informando o número da nota.', null],
        225 => ['A SEFAZ recusou o formato do arquivo da nota.', 'Fale com o suporte do NotaPix informando o número da nota.', null],
        236 => ['A chave de acesso da nota está com erro.', 'Fale com o suporte do NotaPix informando o número da nota.', null],
    ];

    /** @return array{titulo: string, acao: string, botao: ?string, url: ?string}|null */
    public static function explicar(?int $codigo, ?Nota $nota = null): ?array
    {
        if (! $codigo || ! isset(self::TABELA[$codigo])) {
            return null;
        }

        [$titulo, $acao, $destino] = self::TABELA[$codigo];
        [$botao, $url] = self::destino($destino, $nota);

        return ['titulo' => $titulo, 'acao' => $acao, 'botao' => $botao, 'url' => $url];
    }

    /** Texto curto para mensagens de erro (ex.: cancelamento recusado). */
    public static function frase(?int $codigo): ?string
    {
        $e = self::explicar($codigo);

        return $e ? $e['titulo'].' '.$e['acao'] : null;
    }

    private static function destino(?string $destino, ?Nota $nota): array
    {
        return match ($destino) {
            'dados' => ['Abrir Dados da empresa', route('empresa.dados')],
            'certificado' => ['Enviar certificado', route('empresa.certificado')],
            'produtos' => ['Abrir Produtos', route('empresa.produtos.index')],
            'pessoa' => $nota?->destinatario_id
                ? ['Corrigir cadastro da pessoa', route('empresa.pessoas.edit', $nota->destinatario_id)]
                : ['Abrir Pessoas', route('empresa.pessoas.index')],
            'corrigir' => $nota && $nota->podeEditar()
                ? ['Corrigir a nota', $nota->ehNfce() ? route('empresa.nfce.edit', $nota->id) : route('empresa.notas.edit', $nota->id)]
                : [null, null],
            default => [null, null],
        };
    }
}
