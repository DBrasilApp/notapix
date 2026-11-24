<?php

namespace App\Services\Nfe;

use App\Mail\NotaAutorizadaMail;
use App\Models\Auditoria;
use App\Models\CreditoMovimento;
use App\Models\Empresa;
use App\Models\LogSefaz;
use App\Models\Nota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use NFePHP\NFe\Complements;

/**
 * Envia a nota à SEFAZ e cuida de tudo em volta:
 * número, reserva e devolução do crédito, protocolo, arquivos e e-mail.
 *
 * Regras do crédito: reservado ao enviar; consumido se autorizada ou denegada;
 * devolvido se rejeitada (a nota pode ser corrigida e enviada de novo).
 */
class EmissorNfe
{
    public function __construct(
        private MontadorXmlNfe $montador,
        private FabricaClienteSefaz $fabrica,
        private GeradorDanfe $danfe,
    ) {
    }

    /** Reserva número e crédito. Chamado antes de colocar a nota na fila. */
    public function preparar(Nota $nota): Nota
    {
        return DB::transaction(function () use ($nota) {
            // Trava a empresa: dois envios ao mesmo tempo não pegam o mesmo número nem o último crédito
            $empresa = Empresa::query()->lockForUpdate()->findOrFail($nota->empresa_id);
            $nota = Nota::query()->lockForUpdate()->findOrFail($nota->id);

            if (! $nota->podeEditar()) {
                throw new \RuntimeException('Esta nota não pode ser enviada na situação atual.');
            }

            if ($nota->numero === null) {
                // NF-e e NFC-e têm séries e numerações separadas
                $nfce = (int) $nota->modelo === 65;
                $nota->numero = $nfce ? $empresa->nfce_proximo_numero : $empresa->proximo_numero_nfe;
                $nota->serie = $nfce ? $empresa->nfce_serie : $empresa->serie_nfe;
                $nota->ambiente = $empresa->ambiente;
                $empresa->increment($nfce ? 'nfce_proximo_numero' : 'proximo_numero_nfe');
            }

            if ($nota->credito_reserva_id === null) {
                // Da própria empresa ou, sem saldo, do escritório contábil (se ele permitir)
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
                    'nota_id' => $nota->id,
                    'user_id' => auth()->id(),
                    'observacao' => ($nota->ehNfce() ? 'Emissão da NFC-e ' : 'Emissão da NF-e ').$nota->numero,
                ]);
                $nota->credito_reserva_id = $reserva->id;
            }

            $nota->status = Nota::PROCESSANDO;
            $nota->motivo = 'Enviando para a SEFAZ...';
            $nota->codigo_status = null;
            $nota->save();

            return $nota;
        });
    }

    /** Monta, assina e envia. Chamado pela fila. */
    public function transmitir(Nota $nota): Nota
    {
        $nota->loadMissing('empresa.certificadoAtivo', 'itens');
        $nota->increment('tentativas');

        try {
            ['xml' => $xml, 'chave' => $chave] = $this->montador->montar($nota);
            $nota->update(['chave' => $chave]);

            $cliente = $this->fabrica->para($nota->empresa, (int) $nota->modelo);
            $assinado = $cliente->assinar($xml);
        } catch (ErroMontagemNfe $e) {
            return $this->rejeitarLocalmente($nota, 'Dados inválidos para a NF-e: '.implode('; ', $e->erros));
        } catch (\Throwable $e) {
            report($e);

            return $this->rejeitarLocalmente($nota, 'Não foi possível gerar ou assinar o XML: '.$e->getMessage());
        }

        $this->guardar($nota, 'nfe.xml', $assinado);

        try {
            $inicio = microtime(true);
            $resposta = $cliente->autorizar($assinado, str_pad((string) $nota->id, 15, '0', STR_PAD_LEFT));
        } catch (\Throwable $e) {
            // Não sabemos se a SEFAZ recebeu: a nota fica "processando" e o crédito reservado
            report($e);
            $this->registrarLog($nota, 'autorizacao', $cliente->ultimaRequisicao(), null, null, 'Falha de comunicação: '.$e->getMessage(), $inicio ?? null);

            $nota->update([
                'status' => Nota::PROCESSANDO,
                'motivo' => mb_substr('Sem resposta da SEFAZ. Detalhe: '.$e->getMessage(), 0, 255),
            ]);

            return $nota;
        }

        $resultado = ResultadoSefaz::interpretar($resposta);
        $this->registrarLog($nota, 'autorizacao', $cliente->ultimaRequisicao(), $resposta, $resultado->codigo, $resultado->motivo, $inicio);

        // Duplicidade: a SEFAZ já tem esta nota (envio anterior sem resposta). Busca o protocolo.
        if ($resultado->situacao === ResultadoSefaz::DUPLICIDADE) {
            return $this->consultar($nota);
        }

        return $this->aplicar($nota, $resultado, $assinado, $resposta);
    }

    /** Consulta a situação pela chave (nota sem resposta ou em duplicidade). */
    public function consultar(Nota $nota): Nota
    {
        $nota->loadMissing('empresa.certificadoAtivo');

        if (! $nota->chave) {
            return $this->rejeitarLocalmente($nota, 'A nota não chegou a ser montada. Corrija e envie de novo.');
        }

        $cliente = $this->fabrica->para($nota->empresa, (int) $nota->modelo);
        $inicio = microtime(true);

        try {
            $resposta = $cliente->consultarChave($nota->chave);
        } catch (\Throwable $e) {
            report($e);
            $nota->update(['motivo' => mb_substr('A SEFAZ não respondeu à consulta. Detalhe: '.$e->getMessage(), 0, 255)]);

            return $nota;
        }

        $resultado = ResultadoSefaz::interpretar($resposta);
        $this->registrarLog($nota, 'consulta', $cliente->ultimaRequisicao(), $resposta, $resultado->codigo, $resultado->motivo, $inicio);

        if ($resultado->situacao === ResultadoSefaz::NAO_ENCONTRADA) {
            // A SEFAZ não recebeu: libera para corrigir/enviar de novo
            return $this->rejeitarLocalmente($nota, 'A SEFAZ não recebeu esta nota. Você pode enviá-la de novo.', $resultado->codigo);
        }

        $assinado = Storage::disk('local')->get($this->caminho($nota, 'nfe.xml'));

        return $this->aplicar($nota, $resultado, (string) $assinado, $resposta);
    }

    private function aplicar(Nota $nota, ResultadoSefaz $r, string $assinado, string $resposta): Nota
    {
        return match ($r->situacao) {
            ResultadoSefaz::AUTORIZADA => $this->autorizar($nota, $r, $assinado, $resposta),
            ResultadoSefaz::DENEGADA => $this->denegar($nota, $r, $assinado, $resposta),
            ResultadoSefaz::PROCESSANDO => tap($nota)->update([
                'recibo' => $r->recibo, 'codigo_status' => $r->codigo,
                'motivo' => 'A SEFAZ ainda está processando. Use "Consultar situação" em alguns instantes.',
            ]),
            default => $this->rejeitar($nota, $r),
        };
    }

    private function autorizar(Nota $nota, ResultadoSefaz $r, string $assinado, string $resposta): Nota
    {
        $procNFe = Complements::toAuthorize($assinado, $resposta);
        $caminhoXml = $this->guardar($nota, 'procNFe.xml', $procNFe);

        $caminhoPdf = null;
        try {
            $caminhoPdf = $this->guardar($nota, 'danfe.pdf', $this->danfe->gerar($procNFe, nfce: $nota->ehNfce(), bobina: (int) $nota->empresa->nfce_bobina));
        } catch (\Throwable $e) {
            // Sem DANFE agora não impede a nota: dá para gerar de novo depois
            report($e);
        }

        $nota->update([
            'status' => Nota::AUTORIZADA,
            'codigo_status' => $r->codigo,
            'motivo' => $r->motivo,
            'protocolo' => $r->protocolo,
            'autorizada_em' => $r->dataRecebimento ? \Carbon\Carbon::parse($r->dataRecebimento) : now(),
            'data_emissao' => $this->dataEmissao($assinado),
            'xml_path' => $caminhoXml,
            'pdf_path' => $caminhoPdf,
        ]);

        Auditoria::registrar('nota_autorizada', $nota, ['numero' => $nota->numero, 'chave' => $nota->chave]);
        $this->enviarEmail($nota);

        return $nota;
    }

    private function denegar(Nota $nota, ResultadoSefaz $r, string $assinado, string $resposta): Nota
    {
        $caminho = $this->guardar($nota, 'procNFe.xml', Complements::toAuthorize($assinado, $resposta));

        $nota->update([
            'status' => Nota::DENEGADA,
            'codigo_status' => $r->codigo,
            'motivo' => $r->motivo,
            'protocolo' => $r->protocolo,
            'xml_path' => $caminho,
        ]);

        return $nota;
    }

    private function rejeitar(Nota $nota, ResultadoSefaz $r): Nota
    {
        return $this->rejeitarLocalmente($nota, $r->motivo, $r->codigo);
    }

    /** Volta a nota para correção e devolve o crédito reservado. */
    private function rejeitarLocalmente(Nota $nota, string $motivo, ?int $codigo = null): Nota
    {
        DB::transaction(function () use ($nota, $motivo, $codigo) {
            if ($nota->credito_reserva_id) {
                // Devolve para a mesma carteira de onde saiu (empresa ou escritório)
                $reserva = CreditoMovimento::find($nota->credito_reserva_id);
                CreditoMovimento::create([
                    'carteira_type' => $reserva?->carteira_type ?? $nota->empresa->getMorphClass(),
                    'carteira_id' => $reserva?->carteira_id ?? $nota->empresa_id,
                    'tipo' => CreditoMovimento::ESTORNO,
                    'quantidade' => 1,
                    'nota_id' => $nota->id,
                    'observacao' => "Devolução: NF-e {$nota->numero} não autorizada",
                ]);
            }

            $nota->update([
                'status' => Nota::REJEITADA,
                'codigo_status' => $codigo,
                'motivo' => mb_substr($motivo, 0, 255),
                'credito_reserva_id' => null,
            ]);
        });

        return $nota;
    }

    private function caminho(Nota $nota, string $sufixo): string
    {
        // Pasta pelo mês de criação da nota: o mesmo caminho em todas as tentativas
        $mes = ($nota->created_at ?? now())->format('Y/m');

        return "notas/{$nota->empresa_id}/{$mes}/{$nota->chave}-{$sufixo}";
    }

    private function guardar(Nota $nota, string $sufixo, string $conteudo): string
    {
        $caminho = $this->caminho($nota, $sufixo);
        Storage::disk('local')->put($caminho, $conteudo);

        return $caminho;
    }

    private function dataEmissao(string $xml): ?\Carbon\Carbon
    {
        return preg_match('/<dhEmi>([^<]+)<\/dhEmi>/', $xml, $m) ? \Carbon\Carbon::parse($m[1]) : null;
    }

    private function registrarLog(Nota $nota, string $servico, ?string $envio, ?string $retorno, ?int $codigo, ?string $motivo, ?float $inicio): void
    {
        LogSefaz::create([
            'empresa_id' => $nota->empresa_id,
            'nota_id' => $nota->id,
            'servico' => $servico,
            'ambiente' => $nota->ambiente,
            'xml_envio' => $envio,
            'xml_retorno' => $retorno,
            'codigo_status' => $codigo,
            'motivo' => $motivo ? mb_substr($motivo, 0, 255) : null,
            'duracao_ms' => $inicio ? (int) ((microtime(true) - $inicio) * 1000) : null,
        ]);
    }

    private function enviarEmail(Nota $nota): void
    {
        $destinos = array_values(array_unique(array_filter([
            $nota->empresa->email,
            $nota->destinatario_dados['email'] ?? null,
        ])));

        if (! $destinos) {
            return;
        }

        try {
            Mail::to($destinos)->send(new NotaAutorizadaMail($nota));
        } catch (\Throwable $e) {
            // E-mail que falha não pode desfazer uma nota autorizada
            Log::warning("Falha ao enviar e-mail da NF-e {$nota->id}: ".$e->getMessage());
        }
    }
}
