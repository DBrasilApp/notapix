<?php

namespace App\Models;

use App\Models\Concerns\TemCarteiraDeCreditos;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empresa extends Model
{
    use HasFactory, SoftDeletes, TemCarteiraDeCreditos;

    public const AMBIENTE_PRODUCAO = 1;
    public const AMBIENTE_HOMOLOGACAO = 2;

    // Código de Regime Tributário (CRT) da NF-e
    public const CRT_SIMPLES = 1;
    public const CRT_SIMPLES_EXCESSO = 2;
    public const CRT_NORMAL = 3;
    public const CRT_MEI = 4;

    /** Regimes atendidos hoje. Lucro Presumido e Real (CRT 3) ficam para uma etapa futura. */
    public const REGIMES_DISPONIVEIS = [
        self::CRT_SIMPLES => 'Simples Nacional',
        self::CRT_MEI => 'MEI',
    ];

    /** Aparecem na lista, desabilitados, como "em breve". */
    public const REGIMES_FUTUROS = [
        'Lucro Presumido',
        'Lucro Real',
    ];

    protected $table = 'empresas';

    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cnpj', 'inscricao_estadual', 'inscricao_municipal',
        'crt', 'cnae', 'logradouro', 'numero', 'complemento', 'bairro', 'codigo_municipio',
        'municipio', 'uf', 'cep', 'telefone', 'email', 'ambiente', 'serie_nfe',
        'proximo_numero_nfe', 'ativo',
        'nfce_csc_homologacao', 'nfce_csc_id_homologacao', 'nfce_csc_producao', 'nfce_csc_id_producao',
        'nfce_serie', 'nfce_proximo_numero', 'nfce_bobina',
        'nfse_serie', 'nfse_proximo_numero', 'nfse_regime_apuracao', 'nfse_aliquota_simples', 'nfse_aliquota_iss',
    ];

    protected $hidden = ['nfce_csc_homologacao', 'nfce_csc_producao'];

    protected function casts(): array
    {
        return [
            'crt' => 'integer',
            'ambiente' => 'integer',
            'serie_nfe' => 'integer',
            'proximo_numero_nfe' => 'integer',
            'ativo' => 'boolean',
            'nfce_csc_homologacao' => 'encrypted',
            'nfce_csc_producao' => 'encrypted',
            'nfce_serie' => 'integer',
            'nfce_proximo_numero' => 'integer',
            'nfce_bobina' => 'integer',
            'nfse_proximo_numero' => 'integer',
            'nfse_regime_apuracao' => 'integer',
            'nfse_aliquota_simples' => 'decimal:2',
            'nfse_aliquota_iss' => 'decimal:2',
        ];
    }

    /** CSC e ID do CSC do ambiente atual da empresa (NFC-e). */
    public function cscAtual(): array
    {
        return $this->ambiente === self::AMBIENTE_PRODUCAO
            ? ['csc' => (string) $this->nfce_csc_producao, 'id' => (string) $this->nfce_csc_id_producao]
            : ['csc' => (string) $this->nfce_csc_homologacao, 'id' => (string) $this->nfce_csc_id_homologacao];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function escritorios(): BelongsToMany
    {
        return $this->belongsToMany(Escritorio::class, 'escritorio_empresa')
            ->withPivot(['status', 'solicitado_por', 'usar_saldo_escritorio', 'autorizado_em', 'revogado_em'])
            ->withTimestamps();
    }

    /** Escritório contábil que cuida da empresa hoje (no máximo um). */
    public function escritorioAtivo(): ?Escritorio
    {
        return $this->escritorios()->wherePivot('status', 'ativo')->first();
    }

    /**
     * De onde sai o crédito da próxima nota: da própria empresa ou, se ela estiver sem saldo
     * e o escritório permitir, do escritório. null = sem crédito.
     */
    public function carteiraParaEmitir(): Empresa|Escritorio|null
    {
        if ($this->saldoCreditos() >= 1) {
            return $this;
        }

        $escritorio = $this->escritorios()->wherePivot('status', 'ativo')->wherePivot('usar_saldo_escritorio', true)->first();

        return $escritorio && $escritorio->saldoCreditos() >= 1 ? $escritorio : null;
    }

    public function certificados(): HasMany
    {
        return $this->hasMany(Certificado::class);
    }

    public function certificadoAtivo(): HasOne
    {
        return $this->hasOne(Certificado::class)->where('ativo', true)->latestOfMany();
    }

    public function destinatarios(): HasMany
    {
        return $this->hasMany(Destinatario::class);
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class);
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    public function servicos(): HasMany
    {
        return $this->hasMany(Servico::class);
    }

    public function nfses(): HasMany
    {
        return $this->hasMany(Nfse::class);
    }

    public function nfseContratos(): HasMany
    {
        return $this->hasMany(NfseContrato::class);
    }

    public function ehMei(): bool
    {
        return $this->crt === self::CRT_MEI;
    }

    /** O que falta, além do básico, para emitir NFS-e. */
    public function pendenciasNfse(): array
    {
        $pendencias = $this->pendenciasParaEmitir();

        if (! $this->ehMei() && $this->nfse_aliquota_simples === null) {
            $pendencias['nfse'] = 'Informar em Dados da empresa → NFS-e o % aproximado de tributos do Simples';
        }

        return $pendencias;
    }

    public function emHomologacao(): bool
    {
        return $this->ambiente === self::AMBIENTE_HOMOLOGACAO;
    }

    /** O CNPJ só pode ser trocado enquanto nenhuma nota tiver saído do rascunho. */
    public function podeAlterarCnpj(): bool
    {
        return ! $this->notas()->where('status', '!=', 'rascunho')->exists();
    }

    public function dadosCadastraisCompletos(): bool
    {
        // A inscrição estadual não entra aqui: empresa só de serviços (NFS-e) não tem.
        // Quem emite NF-e/NFC-e é cobrado dela na emissão.
        foreach (['razao_social', 'cnpj', 'logradouro', 'numero', 'bairro',
            'codigo_municipio', 'municipio', 'uf', 'cep', 'email'] as $campo) {
            if (blank($this->{$campo})) {
                return false;
            }
        }

        return array_key_exists($this->crt, self::REGIMES_DISPONIVEIS);
    }

    public function temCertificadoValido(): bool
    {
        $certificado = $this->certificadoAtivo;

        return $certificado !== null && $certificado->valido_ate?->isFuture();
    }

    /** O que falta para a empresa poder emitir notas. Lista vazia = pronta. */
    public function pendenciasParaEmitir(): array
    {
        $pendencias = [];

        if (! $this->dadosCadastraisCompletos()) {
            $pendencias['dados'] = 'Completar os dados cadastrais';
        }
        if (! $this->temCertificadoValido()) {
            $pendencias['certificado'] = 'Enviar um certificado digital A1 válido';
        }
        if (! $this->carteiraParaEmitir()) {
            $pendencias['creditos'] = 'Adquirir créditos';
        }

        return $pendencias;
    }
}
