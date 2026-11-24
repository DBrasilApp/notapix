<?php

namespace App\Services\Nfe;

use App\Models\Auditoria;
use App\Models\LogSefaz;
use App\Models\Nota;
use App\Models\NotaEvento;
use App\Models\User;
use DOMDocument;
use Illuminate\Support\Facades\Storage;
use NFePHP\DA\NFe\Daevento;
use NFePHP\NFe\Complements;
use RuntimeException;

/**
 * Eventos da NF-e autorizada: cancelamento (110111) e carta de correção (110110).
 * O crédito usado na emissão não volta no cancelamento (a SEFAZ autorizou a nota).
 */
class EventosNfe
{
    public const CANCELAMENTO = 'cancelamento';
    public const CARTA_CORRECAO = 'carta_correcao';

    /** A SEFAZ aceita no máximo 20 cartas de correção por nota. */
    public const MAX_CARTAS = 20;

    public function __construct(private FabricaClienteSefaz $fabrica, private GeradorDanfe $danfe)
    {
    }

    /** Motivo pelo qual a nota não pode ser cancelada agora (null = pode). */
    public static function impedimentoCancelamento(Nota $nota): ?string
    {
        if ($nota->status !== Nota::AUTORIZADA) {
            return 'Só notas autorizadas podem ser canceladas.';
        }

        $limite = self::limiteCancelamento($nota);
        if ($limite && $limite->isPast()) {
            $prazo = $nota->ehNfce() ? config('nfeexpress.nfce.prazo_cancelamento_minutos').' minutos' : config('nfeexpress.nfe.prazo_cancelamento_horas').' horas';

            return "O prazo para cancelar ({$prazo} após a autorização) terminou em ".$limite->format('d/m/Y H:i')
                .'. Depois disso, o cancelamento só pode ser pedido diretamente à SEFAZ (cancelamento extemporâneo). Fale com seu contador.';
        }

        return null;
    }

    /** Até quando a nota pode ser cancelada: NF-e 24 horas, NFC-e 30 minutos (ES). */
    public static function limiteCancelamento(Nota $nota): ?\Carbon\CarbonInterface
    {
        if (! $nota->autorizada_em) {
            return null;
        }

        return $nota->ehNfce()
            ? $nota->autorizada_em->copy()->addMinutes(config('nfeexpress.nfce.prazo_cancelamento_minutos'))
            : $nota->autorizada_em->copy()->addHours(config('nfeexpress.nfe.prazo_cancelamento_horas'));
    }

    public static function impedimentoCarta(Nota $nota): ?string
    {
        if ($nota->ehNfce()) {
            return 'A NFC-e não aceita carta de correção.';
        }
        if ($nota->status !== Nota::AUTORIZADA) {
            return 'Só notas autorizadas (e não canceladas) aceitam carta de correção.';
        }
        if (self::cartasRegistradas($nota) >= self::MAX_CARTAS) {
            return 'Esta nota já tem 20 cartas de correção, o máximo permitido pela SEFAZ.';
        }

        return null;
    }

    public static function cartasRegistradas(Nota $nota): int
    {
        return (int) $nota->eventos()->where('tipo', self::CARTA_CORRECAO)->where('status', 'registrado')->max('sequencia');
    }

    public function cancelar(Nota $nota, string $justificativa, User $usuario): NotaEvento
    {
        if ($motivo = self::impedimentoCancelamento($nota)) {
            throw new RuntimeException($motivo);
        }

        $evento = $nota->eventos()->create([
            'user_id' => $usuario->id, 'tipo' => self::CANCELAMENTO, 'sequencia' => 1,
            'texto' => $justificativa, 'status' => 'processando',
        ]);

        $this->enviar($nota, $evento, fn (ClienteSefaz $c) => $c->cancelar($nota->chave, $justificativa, (string) $nota->protocolo));

        if ($evento->status === 'registrado') {
            $nota->update(['status' => Nota::CANCELADA, 'motivo' => 'Cancelada: '.mb_substr($justificativa, 0, 230)]);
            $this->danfeCancelado($nota);
            Auditoria::registrar('nota_cancelada', $nota, ['numero' => $nota->numero, 'protocolo' => $evento->protocolo]);
        }

        return $evento;
    }

    public function corrigir(Nota $nota, string $texto, User $usuario): NotaEvento
    {
        if ($motivo = self::impedimentoCarta($nota)) {
            throw new RuntimeException($motivo);
        }

        $sequencia = self::cartasRegistradas($nota) + 1;
        $evento = $nota->eventos()->create([
            'user_id' => $usuario->id, 'tipo' => self::CARTA_CORRECAO, 'sequencia' => $sequencia,
            'texto' => $texto, 'status' => 'processando',
        ]);

        $this->enviar($nota, $evento, fn (ClienteSefaz $c) => $c->cartaCorrecao($nota->chave, $texto, $sequencia));

        if ($evento->status === 'registrado') {
            Auditoria::registrar('carta_correcao', $nota, ['numero' => $nota->numero, 'sequencia' => $sequencia]);
        }

        return $evento;
    }

    /** PDF da carta de correção (ou do cancelamento), a partir do XML do evento. */
    public function pdf(NotaEvento $evento): string
    {
        $empresa = $evento->nota->empresa;
        $xml = (string) Storage::disk('local')->get($evento->xml_path);
        $nivel = error_reporting();

        try {
            $da = new Daevento($xml, [
                'razao' => $empresa->razao_social, 'logradouro' => $empresa->logradouro, 'numero' => $empresa->numero,
                'complemento' => (string) $empresa->complemento, 'bairro' => $empresa->bairro, 'CEP' => $empresa->cep,
                'municipio' => $empresa->municipio, 'UF' => $empresa->uf, 'telefone' => (string) $empresa->telefone,
                'email' => (string) $empresa->email,
            ]);
            $da->debugMode(false);
            $da->creditsIntegratorFooter('Emitido com '.config('nfeexpress.nfe.danfe_creditos'), false);

            return $da->render();
        } finally {
            error_reporting($nivel); // a biblioteca desliga os avisos do PHP
        }
    }

    private function enviar(Nota $nota, NotaEvento $evento, \Closure $chamada): void
    {
        $nota->loadMissing('empresa.certificadoAtivo');
        $cliente = $this->fabrica->para($nota->empresa, (int) $nota->modelo);
        $inicio = microtime(true);

        try {
            $resposta = $chamada($cliente);
        } catch (\Throwable $e) {
            report($e);
            $this->log($nota, $cliente->ultimaRequisicao(), null, null, 'Falha de comunicação: '.$e->getMessage(), $inicio);
            $evento->update(['status' => 'rejeitado', 'motivo' => mb_substr('A SEFAZ não respondeu. Tente de novo em alguns minutos. Detalhe: '.$e->getMessage(), 0, 255)]);

            return;
        }

        $requisicao = $cliente->ultimaRequisicao();
        [$codigo, $motivo, $protocolo] = $this->interpretar($resposta);
        $this->log($nota, $requisicao, $resposta, $codigo, $motivo, $inicio);

        // 135: registrado e vinculado; 136: registrado sem vínculo; 155: cancelamento fora de prazo homologado
        if (! in_array($codigo, [135, 136, 155], true)) {
            $evento->update(['status' => 'rejeitado', 'codigo_status' => $codigo, 'motivo' => mb_substr($motivo, 0, 255)]);

            return;
        }

        $caminho = 'notas/'.$nota->empresa_id.'/'.($nota->created_at ?? now())->format('Y/m')
            ."/{$nota->chave}-evento-{$evento->tipo}-{$evento->sequencia}.xml";
        try {
            Storage::disk('local')->put($caminho, Complements::toAuthorize($requisicao, $resposta));
        } catch (\Throwable $e) {
            report($e);
            Storage::disk('local')->put($caminho, $resposta);
        }

        $evento->update([
            'status' => 'registrado', 'codigo_status' => $codigo, 'motivo' => mb_substr($motivo, 0, 255),
            'protocolo' => $protocolo, 'xml_path' => $caminho,
        ]);
    }

    /** @return array{0: ?int, 1: string, 2: ?string} */
    private function interpretar(string $xml): array
    {
        $dom = new DOMDocument;
        if (trim($xml) === '' || ! @$dom->loadXML($xml)) {
            return [null, 'Resposta da SEFAZ ilegível ou vazia.', null];
        }

        $texto = fn (\DOMElement $no, string $tag) => $no->getElementsByTagName($tag)->length
            ? trim($no->getElementsByTagName($tag)->item(0)->nodeValue) : null;

        // Resultado do evento fica em retEvento; sem ele, vale o do lote
        $no = $dom->getElementsByTagName('retEvento')->item(0) ?? $dom->documentElement;

        return [(int) $texto($no, 'cStat') ?: null, (string) $texto($no, 'xMotivo'), $texto($no, 'nProt')];
    }

    private function danfeCancelado(Nota $nota): void
    {
        if (! $nota->xml_path || ! Storage::disk('local')->exists($nota->xml_path)) {
            return;
        }

        try {
            $pdf = $this->danfe->gerar((string) Storage::disk('local')->get($nota->xml_path), cancelada: true,
                nfce: $nota->ehNfce(), bobina: (int) $nota->empresa->nfce_bobina);
            $caminho = $nota->pdf_path ?: preg_replace('/procNFe\.xml$/', 'danfe.pdf', $nota->xml_path);
            Storage::disk('local')->put($caminho, $pdf);
            $nota->update(['pdf_path' => $caminho]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function log(Nota $nota, ?string $envio, ?string $retorno, ?int $codigo, ?string $motivo, float $inicio): void
    {
        LogSefaz::create([
            'empresa_id' => $nota->empresa_id,
            'nota_id' => $nota->id,
            'servico' => 'evento',
            'ambiente' => $nota->ambiente,
            'xml_envio' => $envio,
            'xml_retorno' => $retorno,
            'codigo_status' => $codigo,
            'motivo' => $motivo ? mb_substr($motivo, 0, 255) : null,
            'duracao_ms' => (int) ((microtime(true) - $inicio) * 1000),
        ]);
    }
}
