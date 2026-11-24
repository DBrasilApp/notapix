<?php

namespace App\Models;

use App\Support\Formata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nota fiscal de serviço (NFS-e Nacional), emitida a partir de uma DPS. */
class Nfse extends Model
{
    public const RASCUNHO = 'rascunho';
    public const PROCESSANDO = 'processando';
    public const AUTORIZADA = 'autorizada';
    public const REJEITADA = 'rejeitada';
    public const CANCELADA = 'cancelada';

    public const STATUS = [
        self::RASCUNHO => 'Rascunho',
        self::PROCESSANDO => 'Enviando',
        self::AUTORIZADA => 'Autorizada',
        self::REJEITADA => 'Rejeitada',
        self::CANCELADA => 'Cancelada',
    ];

    /** Retenções federais que o tomador pode descontar (campo => rótulo). */
    public const RETENCOES = [
        'ret_inss' => 'INSS',
        'ret_irrf' => 'IRRF',
        'ret_csll' => 'CSLL',
        'ret_pis' => 'PIS',
        'ret_cofins' => 'COFINS',
    ];

    protected $table = 'nfses';

    protected $fillable = [
        'empresa_id', 'user_id', 'servico_id', 'destinatario_id', 'contrato_id', 'tomador_dados', 'lote',
        'ambiente', 'serie', 'numero_dps', 'id_dps', 'competencia',
        'ctribnac', 'ctribmun', 'cnbs', 'municipio_prestacao', 'discriminacao', 'informacoes_complementares',
        'valor_servico', 'iss_retido', 'aliquota_iss', 'valor_iss_retido',
        'ret_inss', 'ret_irrf', 'ret_csll', 'ret_pis', 'ret_cofins', 'valor_liquido',
        'status', 'codigo_status', 'motivo', 'chave', 'numero_nfse', 'autorizada_em',
        'dps_path', 'xml_path', 'pdf_path', 'credito_reserva_id', 'tentativas',
        'cancelada_em', 'cancelamento_motivo', 'cancelamento_xml_path',
        'email_enviado_em', 'email_destinos', 'email_erro',
    ];

    protected function casts(): array
    {
        return [
            'tomador_dados' => 'array',
            'ambiente' => 'integer',
            'numero_dps' => 'integer',
            'competencia' => 'date',
            'valor_servico' => 'decimal:2',
            'iss_retido' => 'boolean',
            'aliquota_iss' => 'decimal:2',
            'valor_iss_retido' => 'decimal:2',
            'ret_inss' => 'decimal:2',
            'ret_irrf' => 'decimal:2',
            'ret_csll' => 'decimal:2',
            'ret_pis' => 'decimal:2',
            'ret_cofins' => 'decimal:2',
            'valor_liquido' => 'decimal:2',
            'autorizada_em' => 'datetime',
            'cancelada_em' => 'datetime',
            'email_enviado_em' => 'datetime',
            'tentativas' => 'integer',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class)->withTrashed();
    }

    public function tomador(): BelongsTo
    {
        return $this->belongsTo(Destinatario::class, 'destinatario_id')->withTrashed();
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(NfseContrato::class, 'contrato_id');
    }

    public function podeEditar(): bool
    {
        return in_array($this->status, [self::RASCUNHO, self::REJEITADA], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    public function totalRetencoesFederais(): float
    {
        return round(array_sum(array_map(fn ($c) => (float) $this->{$c}, array_keys(self::RETENCOES))), 2);
    }

    public function tomadorNome(): string
    {
        return (string) ($this->tomador_dados['razao_social'] ?? 'Sem tomador identificado');
    }

    public function tomadorDocumento(): string
    {
        $doc = (string) ($this->tomador_dados['cpf_cnpj'] ?? '');

        return match (strlen($doc)) {
            11 => Formata::cpf($doc),
            14 => Formata::cnpj($doc),
            default => $doc,
        };
    }

    /** "11/2026" */
    public function competenciaTexto(): string
    {
        return $this->competencia?->format('m/Y') ?? '';
    }
}
