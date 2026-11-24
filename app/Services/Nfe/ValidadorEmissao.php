<?php

namespace App\Services\Nfe;

use App\Models\Destinatario;
use App\Models\Nota;
use App\Support\ChaveAcesso;
use App\Support\Formata;

/**
 * Confere, antes de gastar crédito e chamar a SEFAZ, tudo o que já sabemos que daria rejeição
 * ou que o sistema ainda não sabe emitir. Devolve a lista de problemas (vazia = pode emitir).
 */
class ValidadorEmissao
{
    /** CSOSN que o sistema já sabe emitir (sem ST e sem crédito de ICMS). */
    public const CSOSN_SUPORTADOS = ['102', '103', '300', '400', '500'];

    /** CST de PIS/COFINS sem cálculo de contribuição (os usados no Simples Nacional). */
    public const CST_PIS_COFINS_SUPORTADOS = ['04', '05', '06', '07', '08', '09', '49', '99'];

    public function problemas(Nota $nota): array
    {
        $empresa = $nota->empresa;
        $problemas = [];

        if (! $nota->podeEditar()) {
            return ['Esta nota não pode mais ser enviada (situação: '.$nota->statusLabel().').'];
        }

        if (! $empresa->dadosCadastraisCompletos()) {
            $problemas[] = 'Complete os dados da empresa (endereço, inscrição estadual e regime tributário).';
        } elseif (blank($empresa->inscricao_estadual)) {
            $problemas[] = 'Para emitir NF-e ou NFC-e a empresa precisa da inscrição estadual. Informe em Dados da empresa.';
        }

        if (! $empresa->temCertificadoValido()) {
            $problemas[] = 'Envie um certificado digital A1 válido da empresa.';
        }

        // Nota rejeitada já teve o crédito devolvido: precisa de saldo de novo
        $temReserva = $nota->credito_reserva_id !== null;
        if (! $temReserva && ! $empresa->carteiraParaEmitir()) {
            $problemas[] = 'A empresa não tem créditos para emitir. Adquira créditos e tente novamente.';
        }

        $dest = $nota->destinatario_dados ?? [];

        // NFC-e: consumidor identificado só se quiser (acima do limite, obrigatório)
        if ($nota->ehNfce()) {
            $cscId = $empresa->cscAtual();
            if (($cscId['csc'] === '') !== ($cscId['id'] === '')) {
                $ambiente = $empresa->emHomologacao() ? 'homologação' : 'produção';
                $problemas[] = $cscId['id'] === ''
                    ? "Falta o ID do CSC de {$ambiente} (Dados da empresa → 4. NFC-e). O CSC já está cadastrado; informe o ID que veio junto com ele (só números)."
                    : "Falta o código CSC de {$ambiente} (Dados da empresa → 4. NFC-e). O ID está cadastrado, mas o código não.";
            }
            if ((float) $nota->valor_total >= (float) config('nfeexpress.nfce.valor_identificacao_obrigatoria') && blank($dest['cpf_cnpj'] ?? null)) {
                $problemas[] = 'Acima de R$ '.number_format((float) config('nfeexpress.nfce.valor_identificacao_obrigatoria'), 2, ',', '.').' é obrigatório informar o CPF ou CNPJ do consumidor.';
            }
            $dest = null;
        }

        foreach ($dest === null ? [] : ['cpf_cnpj', 'razao_social', 'logradouro', 'numero', 'bairro', 'codigo_municipio', 'municipio', 'uf'] as $campo) {
            if (blank($dest[$campo] ?? null)) {
                $problemas[] = 'O cadastro do destinatário está incompleto (endereço e código do município). Corrija em Pessoas e salve a nota de novo.';
                break;
            }
        }

        if ($dest !== null && $nota->id_dest === 2 && $nota->ind_final === 1 && (int) ($dest['ind_ie_dest'] ?? 9) === Destinatario::IE_NAO_CONTRIBUINTE) {
            $problemas[] = 'Venda para consumidor final não contribuinte de outro estado (com DIFAL) ainda não é suportada pelo sistema.';
        }

        if ($nota->itens->isEmpty()) {
            $problemas[] = 'A nota não tem itens.';
        }

        if ($nota->ehDevolucao()) {
            $problemas = array_merge($problemas, $this->problemasDevolucao($nota, $dest));
        }

        foreach ($nota->itens as $item) {
            // CSOSN 900 é usado pelo sistema só na devolução de compra (com o ICMS da compra destacado)
            if ($item->cst_csosn === '900' && $nota->ehDevolucao()) {
                // ok
            } elseif (! in_array($item->cst_csosn, self::CSOSN_SUPORTADOS, true)) {
                $problemas[] = "Item {$item->numero_item} ({$item->descricao}): o CSOSN {$item->cst_csosn} ainda não é suportado na emissão. Use 102, 103, 300, 400 ou 500.";
            }
            foreach (['cst_pis' => 'PIS', 'cst_cofins' => 'COFINS'] as $campo => $nome) {
                if (! in_array($item->{$campo}, self::CST_PIS_COFINS_SUPORTADOS, true)) {
                    $problemas[] = "Item {$item->numero_item} ({$item->descricao}): o CST {$item->{$campo}} do {$nome} ainda não é suportado na emissão.";
                }
            }
        }

        if ((float) $nota->valor_total <= 0) {
            $problemas[] = 'O valor total da nota precisa ser maior que zero.';
        }

        return array_values(array_unique($problemas));
    }

    private function problemasDevolucao(Nota $nota, array $dest): array
    {
        $problemas = [];

        if (empty($nota->referencias)) {
            return ['Informe a nota de compra que está sendo devolvida.'];
        }

        // O destinatário da devolução é quem vendeu (emitente da nota de compra)
        $documento = $dest['cpf_cnpj'] ?? '';
        foreach ($nota->referencias as $ref) {
            $cnpjCompra = ($ref['tipo'] ?? 'nfe') === 'nf' ? ($ref['cnpj'] ?? null) : (ChaveAcesso::decompor($ref['chave'])['cnpj'] ?? null);
            if ($cnpjCompra && $cnpjCompra !== $documento) {
                $problemas[] = 'Na devolução, o destinatário deve ser o fornecedor que emitiu a nota de compra (CNPJ '.Formata::cnpj($cnpjCompra).').';
                break;
            }
        }

        foreach ($nota->itens as $item) {
            if ((float) $item->valor_icms > 0 && ((float) $item->base_icms <= 0 || (float) $item->aliq_icms <= 0)) {
                $problemas[] = "Item {$item->numero_item} ({$item->descricao}): informe a base e a alíquota do ICMS devolvido.";
            }
            if ((float) $item->valor_icms_st > 0 && ((float) $item->base_icms_st <= 0 || (float) $item->aliq_icms_st <= 0)) {
                $problemas[] = "Item {$item->numero_item} ({$item->descricao}): informe a base e a alíquota do ICMS-ST devolvido.";
            }
        }

        return $problemas;
    }
}
