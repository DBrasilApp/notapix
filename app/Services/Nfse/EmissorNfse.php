<?php

namespace App\Services\Nfse;

use App\Models\Auditoria;
use App\Models\CreditoMovimento;
use App\Models\Empresa;
use App\Models\LogSefaz;
use App\Models\Nfse;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Emite e cancela NFS-e na SEFIN Nacional e cuida de tudo em volta: número da DPS, crédito, arquivos.
 *
 * Crédito: reservado ao enviar; consumido se autorizada; devolvido (à mesma carteira) se rejeitada.
 * O cancelamento não devolve o crédito, como na NF-e.
 */
class EmissorNfse
{
    public function __construct(
        private MontadorDps $montador,
        private FabricaClienteSefin $fabrica,
        private GeradorDanfse $danfse,
    ) {
    }

    /** Reserva o número da DPS e o crédito. */
    public function preparar(Nfse $nfse): Nfse
    {
        return DB::transaction(function () use ($nfse) {
            $empresa = Empresa::query()->lockForUpdate()->findOrFail($nfse->empresa_id);
            $nfse = Nfse::query()->lockForUpdate()->findOrFail($nfse->id);

            if (! $nfse->podeEditar()) {
                throw new \RuntimeException('Esta NFS-e não pode ser enviada na situação atual.');
            }

            // Nota rejeitada num ambiente e reenviada depois que a empresa trocou de ambiente: DPS nova
            if ($nfse->numero_dps === null || (int) $nfse->ambiente !== (int) $empresa->ambiente) {
                $nfse->serie = (string) ((int) $empresa->nfse_serie ?: 50);
                $nfse->numero_dps = max(1, (int) $empresa->nfse_proximo_numero);
                $nfse->ambiente = $empresa->ambiente;
                $nfse->id_dps = MontadorDps::idDps($empresa, $nfse->serie, $nfse->numero_dps);
                $empresa->increment('nfse_proximo_numero');
            }

            if ($nfse->credito_reserva_id === null) {
                $carteira = $empresa->carteiraParaEmitir();
                if (! $carteira) {
                    throw new \RuntimeException('A empresa não tem créditos para emitir.');
                }
                if ($carteira !== $empresa) {
                    $carteira = $carteira::query()->lockForUpdate()->findOrFail($carteira->id);
                    if ($carteira->saldoCreditos() < 1) {
                        throw new \RuntimeException('A empresa não tem créditos para emitir.');
                    }
                }

                $reserva = CreditoMovimento::create([
                    'carteira_type' => $carteira->getMorphClass(),
                    'carteira_id' => $carteira->id,
                    'tipo' => CreditoMovimento::RESERVA,
                    'quantidade' => -1,
                    'nfse_id' => $nfse->id,
                    'user_id' => auth()->id(),
                    'observacao' => "Emissão da NFS-e (DPS {$nfse->numero_dps})",
                ]);
                $nfse->credito_reserva_id = $reserva->id;
            }

            $nfse->status = Nfse::PROCESSANDO;
            $nfse->motivo = 'Enviando para a SEFIN Nacional...';
            $nfse->codigo_status = null;
            $nfse->save();

            return $nfse;
        });
    }

    /** Monta, assina e envia a DPS. A resposta é imediata (a SEFIN gera a NFS-e na hora). */
    public function transmitir(Nfse $nfse): Nfse
    {
        $nfse->loadMissing('empresa.certificadoAtivo');
        $nfse->increment('tentativas');

        try {
            $dps = $this->montador->montar($nfse)['xml'];
            $cliente = $this->fabrica->para($nfse->empresa);
            $assinada = $cliente->assinar($dps, 'infDPS');
        } catch (\Throwable $e) {
            report($e);

            return $this->rejeitarLocalmente($nfse, 'Não foi possível gerar ou assinar a DPS: '.$e->getMessage());
        }

        $nfse->update(['dps_path' => $this->guardar($nfse, 'dps.xml', $assinada)]);

        $inicio = microtime(true);
        $resposta = $cliente->emitir($assinada);
        $this->registrarLog($nfse, 'nfse_emissao', $cliente->ultimaRequisicao(), $resposta, $inicio);

        if ($resposta->ok()) {
            return $this->autorizar($nfse, $resposta->xml);
        }

        if ($resposta->situacao === RespostaSefin::INCERTA) {
            $nfse->update([
                'motivo' => mb_substr(($resposta->mensagem ?: 'Sem resposta da SEFIN Nacional.').' Use "Consultar situação" antes de enviar de novo.', 0, 1000),
            ]);

            return $nfse;
        }

        // Recusada: se a DPS já virou NFS-e num envio anterior (resposta perdida), aproveita a nota
        try {
            if ($chave = $cliente->chavePorDps($nfse->id_dps)) {
                return $this->buscarEAutorizar($nfse, $cliente, $chave);
            }
        } catch (\Throwable) {
            // Sem como conferir agora: segue com a rejeição
        }

        return $this->rejeitarLocalmente($nfse, $resposta->textoErros() ?: 'A SEFIN Nacional recusou a DPS.', $resposta->codigo());
    }

    /** Consulta pela DPS: resolve as notas que ficaram sem resposta. */
    public function consultar(Nfse $nfse): Nfse
    {
        $nfse->loadMissing('empresa.certificadoAtivo');

        if (! $nfse->id_dps) {
            return $this->rejeitarLocalmente($nfse, 'A DPS não chegou a ser montada. Corrija e envie de novo.');
        }

        $cliente = $this->fabrica->para($nfse->empresa);

        try {
            $chave = $cliente->chavePorDps($nfse->id_dps);
        } catch (\Throwable $e) {
            $nfse->update(['motivo' => mb_substr('A SEFIN Nacional não respondeu à consulta: '.$e->getMessage(), 0, 1000)]);

            return $nfse;
        }

        if (! $chave) {
            return $nfse->status === Nfse::PROCESSANDO
                ? $this->rejeitarLocalmente($nfse, 'A SEFIN Nacional não recebeu esta DPS. Você pode enviá-la de novo.')
                : $nfse;
        }

        return $this->buscarEAutorizar($nfse, $cliente, $chave);
    }

    /** Cancelamento direto (evento 101101). Motivos: 1 erro na emissão, 2 serviço não prestado, 9 outros. */
    public function cancelar(Nfse $nfse, int $motivo, string $justificativa): array
    {
        $nfse->loadMissing('empresa.certificadoAtivo');

        if ($nfse->status !== Nfse::AUTORIZADA || ! $nfse->chave) {
            return [false, 'Só é possível cancelar uma NFS-e autorizada.'];
        }

        $cliente = $this->fabrica->para($nfse->empresa);

        try {
            $pedido = $cliente->assinar(self::pedidoCancelamento($nfse, $motivo, $justificativa), 'infPedReg');
        } catch (\Throwable $e) {
            report($e);

            return [false, 'Não foi possível assinar o pedido: '.$e->getMessage()];
        }

        $inicio = microtime(true);
        $resposta = $cliente->registrarEvento($nfse->chave, $pedido);
        $this->registrarLog($nfse, 'nfse_cancelamento', $pedido, $resposta, $inicio);

        if (! $resposta->ok()) {
            if ($resposta->situacao === RespostaSefin::INCERTA) {
                return [false, ($resposta->mensagem ?: 'Sem resposta da SEFIN Nacional.').' Aguarde alguns minutos e confira antes de pedir de novo.'];
            }

            return [false, RejeicoesSefin::frase($resposta->codigo()) ?? $resposta->textoErros()];
        }

        $caminho = $this->guardar($nfse, 'cancelamento.xml', (string) $resposta->xml);
        $nfse->update([
            'status' => Nfse::CANCELADA,
            'cancelada_em' => now(),
            'cancelamento_motivo' => mb_substr($justificativa, 0, 255),
            'cancelamento_xml_path' => $caminho,
        ]);
        $this->gerarPdf($nfse);

        Auditoria::registrar('nfse_cancelada', $nfse, ['chave' => $nfse->chave, 'motivo' => $motivo]);

        return [true, 'NFS-e cancelada.'];
    }

    /** O PDF (DANFSe) a partir do XML guardado. */
    public function gerarPdf(Nfse $nfse): ?string
    {
        if (! $nfse->xml_path || ! Storage::disk('local')->exists($nfse->xml_path)) {
            return null;
        }

        try {
            $pdf = $this->danfse->gerar($nfse->fresh(['empresa']));
            $caminho = $this->guardar($nfse, 'danfse.pdf', $pdf);
            $nfse->update(['pdf_path' => $caminho]);

            return $caminho;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function pedidoCancelamento(Nfse $nfse, int $motivo, string $justificativa, ?CarbonImmutable $agora = null): string
    {
        $agora ??= CarbonImmutable::now('America/Sao_Paulo');
        $dom = new DOMDocument('1.0', 'UTF-8');
        $raiz = $dom->createElementNS(MontadorDps::NS, 'pedRegEvento');
        $raiz->setAttribute('versao', MontadorDps::VERSAO);
        $dom->appendChild($raiz);

        $inf = $dom->createElement('infPedReg');
        $inf->setAttribute('Id', 'PRE'.$nfse->chave.'101101');
        $raiz->appendChild($inf);

        $add = function ($pai, string $nome, string $valor) use ($dom) {
            $el = $dom->createElement($nome);
            $el->appendChild($dom->createTextNode($valor));
            $pai->appendChild($el);

            return $el;
        };

        $add($inf, 'tpAmb', (string) $nfse->ambiente);
        $add($inf, 'verAplic', MontadorDps::VER_APLIC);
        $add($inf, 'dhEvento', MontadorDps::dhEmi($agora));
        $add($inf, 'CNPJAutor', (string) $nfse->empresa->cnpj);
        $add($inf, 'chNFSe', (string) $nfse->chave);
        $evento = $dom->createElement('e101101');
        $inf->appendChild($evento);
        $add($evento, 'xDesc', 'Cancelamento de NFS-e'); // com acento: a SEFIN recusa o texto sem acento
        $add($evento, 'cMotivo', (string) $motivo);
        $add($evento, 'xMotivo', mb_substr(trim(preg_replace('/\s+/', ' ', $justificativa)), 0, 255));

        return '<?xml version="1.0" encoding="UTF-8"?>'.$dom->saveXML($raiz);
    }

    private function buscarEAutorizar(Nfse $nfse, ClienteSefin $cliente, string $chave): Nfse
    {
        $resposta = $cliente->consultarNfse($chave);

        if (! $resposta->ok()) {
            $nfse->update(['chave' => $chave, 'motivo' => 'A NFS-e foi gerada, mas não foi possível baixá-la agora. Use "Consultar situação" de novo em instantes.']);

            return $nfse;
        }

        return $this->autorizar($nfse, $resposta->xml);
    }

    private function autorizar(Nfse $nfse, string $xml): Nfse
    {
        $leitor = new LeitorNfse($xml);
        $nfse->chave = $leitor->chave();
        $caminho = $this->guardar($nfse, 'nfse.xml', $xml);

        $nfse->update([
            'status' => Nfse::AUTORIZADA,
            'codigo_status' => null,
            'motivo' => 'NFS-e gerada',
            'numero_nfse' => $leitor->numero(),
            'autorizada_em' => $leitor->dataProcessamento() ? Carbon::parse($leitor->dataProcessamento()) : now(),
            'xml_path' => $caminho,
        ]);

        $this->gerarPdf($nfse);
        Auditoria::registrar('nfse_autorizada', $nfse, ['numero' => $nfse->numero_nfse, 'chave' => $nfse->chave]);

        $this->enviarEmail($nfse->fresh(['empresa']));

        return $nfse;
    }

    /**
     * Manda o XML e o PDF por e-mail: ao tomador, com cópia para a empresa.
     * Um e-mail que falha nunca desfaz a nota; o erro fica anotado para reenviar depois.
     *
     * @param  string[]|null  $destinos  null = tomador + empresa
     * @return array{0: bool, 1: string}
     */
    public function enviarEmail(Nfse $nfse, ?array $destinos = null): array
    {
        if (! in_array($nfse->status, [Nfse::AUTORIZADA], true)) {
            return [false, 'Só é possível enviar uma NFS-e autorizada.'];
        }

        $destinos ??= [$nfse->tomador_dados['email'] ?? null, $nfse->empresa->email];
        $destinos = array_values(array_unique(array_filter(
            array_map(fn ($e) => mb_strtolower(trim((string) $e)), $destinos),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        )));

        if (! $destinos) {
            return [false, 'Nenhum e-mail para enviar (o tomador está sem e-mail no cadastro).'];
        }

        try {
            \Illuminate\Support\Facades\Mail::to($destinos)->send(new \App\Mail\NfseAutorizadaMail($nfse));
            $nfse->update(['email_enviado_em' => now(), 'email_destinos' => mb_substr(implode(', ', $destinos), 0, 500), 'email_erro' => null]);

            return [true, 'E-mail enviado para '.implode(', ', $destinos).'.'];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Falha ao enviar e-mail da NFS-e {$nfse->id}: ".$e->getMessage());
            $nfse->update(['email_erro' => mb_substr($e->getMessage(), 0, 500)]);

            return [false, 'Não foi possível enviar o e-mail: '.$e->getMessage()];
        }
    }

    /** Volta para correção e devolve o crédito à carteira de onde saiu. */
    private function rejeitarLocalmente(Nfse $nfse, string $motivo, ?string $codigo = null): Nfse
    {
        DB::transaction(function () use ($nfse, $motivo, $codigo) {
            if ($nfse->credito_reserva_id) {
                $reserva = CreditoMovimento::find($nfse->credito_reserva_id);
                CreditoMovimento::create([
                    'carteira_type' => $reserva?->carteira_type ?? $nfse->empresa->getMorphClass(),
                    'carteira_id' => $reserva?->carteira_id ?? $nfse->empresa_id,
                    'tipo' => CreditoMovimento::ESTORNO,
                    'quantidade' => 1,
                    'nfse_id' => $nfse->id,
                    'observacao' => "Devolução: NFS-e (DPS {$nfse->numero_dps}) não autorizada",
                ]);
            }

            $nfse->update([
                'status' => Nfse::REJEITADA,
                'codigo_status' => $codigo ? mb_substr($codigo, 0, 10) : null,
                'motivo' => mb_substr($motivo, 0, 2000),
                'credito_reserva_id' => null,
            ]);
        });

        return $nfse;
    }

    private function guardar(Nfse $nfse, string $sufixo, string $conteudo): string
    {
        $mes = ($nfse->created_at ?? now())->format('Y/m');
        $caminho = "nfse/{$nfse->empresa_id}/{$mes}/{$nfse->id}-{$sufixo}";
        Storage::disk('local')->put($caminho, $conteudo);

        return $caminho;
    }

    private function registrarLog(Nfse $nfse, string $servico, ?string $envio, RespostaSefin $r, float $inicio): void
    {
        LogSefaz::create([
            'empresa_id' => $nfse->empresa_id,
            'nfse_id' => $nfse->id,
            'servico' => $servico,
            'ambiente' => $nfse->ambiente,
            'xml_envio' => $envio,
            'xml_retorno' => $r->ok() ? $r->xml : $r->bruto,
            'motivo' => mb_substr($r->ok() ? 'Sucesso' : ($r->textoErros() ?: 'HTTP '.$r->http), 0, 255),
            'duracao_ms' => (int) ((microtime(true) - $inicio) * 1000),
        ]);
    }
}
