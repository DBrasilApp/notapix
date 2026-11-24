<?php

namespace App\Models;

use App\Support\Formata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nota extends Model
{
    public const RASCUNHO = 'rascunho';
    public const PROCESSANDO = 'processando';
    public const AUTORIZADA = 'autorizada';
    public const REJEITADA = 'rejeitada';
    public const DENEGADA = 'denegada';
    public const CANCELADA = 'cancelada';

    public const STATUS = [
        self::RASCUNHO => 'Rascunho',
        self::PROCESSANDO => 'Processando',
        self::AUTORIZADA => 'Autorizada',
        self::REJEITADA => 'Rejeitada',
        self::DENEGADA => 'Denegada',
        self::CANCELADA => 'Cancelada',
    ];

    /** Formas de pagamento (tPag) oferecidas na tela. */
    public const FORMAS_PAGAMENTO = [
        '01' => 'Dinheiro',
        '17' => 'PIX',
        '15' => 'Boleto bancário',
        '03' => 'Cartão de crédito',
        '04' => 'Cartão de débito',
        '05' => 'Crédito loja',
        '90' => 'Sem pagamento',
        '99' => 'Outros',
    ];

    /**
     * Tipos de operação oferecidos na emissão. Cada um define a natureza da operação,
     * o CFOP padrão dos itens (null = o do produto), a finalidade e se há pagamento.
     */
    public const OPERACOES = [
        'venda' => [
            'nome' => 'Venda de mercadoria', 'natureza' => 'Venda de mercadoria', 'cfop' => null,
            'finalidade' => 1, 'pagamento' => true,
            'ajuda' => 'Venda comum para o seu cliente.',
        ],
        'remessa' => [
            'nome' => 'Simples remessa', 'natureza' => 'Simples remessa', 'cfop' => '5949',
            'finalidade' => 1, 'pagamento' => false,
            'ajuda' => 'Envio de mercadoria sem venda (ex.: para conserto, demonstração, depósito).',
        ],
        'bonificacao' => [
            'nome' => 'Bonificação / brinde', 'natureza' => 'Bonificacao, doacao ou brinde', 'cfop' => '5910',
            'finalidade' => 1, 'pagamento' => false,
            'ajuda' => 'Mercadoria dada sem cobrança: bonificação (abono), doação ou brinde.',
        ],
        'devolucao' => [
            'nome' => 'Devolução de compra', 'natureza' => 'Devolucao de compra', 'cfop' => '5202',
            'finalidade' => 4, 'pagamento' => false,
            'ajuda' => 'Devolver ao fornecedor mercadoria que você comprou. Precisa da nota de compra.',
        ],
    ];

    public const MODALIDADES_FRETE = [
        9 => 'Sem frete',
        0 => 'Por conta do emitente (CIF)',
        1 => 'Por conta do destinatário (FOB)',
        2 => 'Por conta de terceiros',
        3 => 'Transporte próprio do emitente',
        4 => 'Transporte próprio do destinatário',
    ];

    protected $table = 'notas';

    protected $fillable = [
        'empresa_id', 'destinatario_id', 'destinatario_dados', 'user_id', 'modelo', 'serie',
        'numero', 'chave', 'ambiente', 'status', 'operacao', 'referencias', 'natureza_operacao', 'tipo_operacao',
        'finalidade', 'id_dest', 'ind_final', 'ind_pres', 'data_emissao', 'data_saida',
        'modalidade_frete', 'forma_pagamento', 'ind_pagamento', 'descricao_pagamento', 'pagamentos', 'troco',
        'valor_produtos', 'valor_desconto', 'valor_frete', 'valor_seguro',
        'valor_outras', 'valor_icms', 'valor_icms_st', 'valor_ipi', 'valor_ipi_devolvido', 'valor_pis', 'valor_cofins',
        'valor_ibs', 'valor_cbs', 'valor_total', 'informacoes_complementares', 'recibo',
        'protocolo', 'codigo_status', 'motivo', 'tentativas', 'autorizada_em', 'xml_path', 'pdf_path',
        'credito_reserva_id',
    ];

    protected function casts(): array
    {
        $casts = [
            'destinatario_dados' => 'array',
            'referencias' => 'array',
            'pagamentos' => 'array',
            'data_emissao' => 'datetime',
            'data_saida' => 'datetime',
            'autorizada_em' => 'datetime',
            'ambiente' => 'integer',
            'numero' => 'integer',
            'serie' => 'integer',
            'id_dest' => 'integer',
            'ind_final' => 'integer',
            'modalidade_frete' => 'integer',
            'ind_pagamento' => 'integer',
        ];

        foreach ([
            'valor_produtos', 'valor_desconto', 'valor_frete', 'valor_seguro', 'valor_outras',
            'valor_icms', 'valor_icms_st', 'valor_ipi', 'valor_ipi_devolvido', 'valor_pis', 'valor_cofins',
            'valor_ibs', 'valor_cbs', 'valor_total', 'troco',
        ] as $campo) {
            $casts[$campo] = 'decimal:2';
        }

        return $casts;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(Destinatario::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(NotaItem::class)->orderBy('numero_item');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(NotaEvento::class);
    }

    public function creditoReserva(): BelongsTo
    {
        return $this->belongsTo(CreditoMovimento::class, 'credito_reserva_id');
    }

    public function logsSefaz(): HasMany
    {
        return $this->hasMany(LogSefaz::class);
    }

    /** Rascunho e nota rejeitada podem ser corrigidos e enviados de novo. */
    public function podeEditar(): bool
    {
        return in_array($this->status, [self::RASCUNHO, self::REJEITADA], true);
    }

    /** Só rascunho que nunca recebeu número pode ser apagado sem deixar buraco na numeração. */
    public function podeExcluir(): bool
    {
        return $this->status === self::RASCUNHO && $this->numero === null;
    }

    public function statusLabel(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    public function nomeDestinatario(): string
    {
        return $this->destinatario_dados['razao_social'] ?? '-';
    }

    public function documentoDestinatario(): string
    {
        $doc = $this->destinatario_dados['cpf_cnpj'] ?? '';

        return strlen($doc) === 11 ? Formata::cpf($doc) : Formata::cnpj($doc);
    }

    public function numeroFormatado(): string
    {
        return $this->numero ? number_format($this->numero, 0, ',', '.') : '-';
    }

    public function chaveFormatada(): string
    {
        return $this->chave ? trim(chunk_split($this->chave, 4, ' ')) : '';
    }

    public function ehDevolucao(): bool
    {
        return $this->operacao === 'devolucao';
    }

    public function operacaoLabel(): string
    {
        return self::OPERACOES[$this->operacao ?? 'venda']['nome'] ?? (string) $this->natureza_operacao;
    }

    public function ehNfce(): bool
    {
        return (int) $this->modelo === 65;
    }
}
