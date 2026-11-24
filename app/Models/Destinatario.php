<?php

namespace App\Models;

use App\Support\Formata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pessoa (física ou jurídica) que recebe as notas da empresa.
 * Na tela aparece como "Pessoas"; no banco a tabela é "destinatarios".
 */
class Destinatario extends Model
{
    use SoftDeletes;

    public const FISICA = 'F';
    public const JURIDICA = 'J';

    // Indicador da IE do destinatário (campo indIEDest da NF-e)
    public const IE_CONTRIBUINTE = 1;
    public const IE_ISENTO = 2;
    public const IE_NAO_CONTRIBUINTE = 9;

    public const INDICADORES_IE = [
        self::IE_CONTRIBUINTE => 'Contribuinte do ICMS (tem inscrição estadual)',
        self::IE_ISENTO => 'Isento de inscrição estadual',
        self::IE_NAO_CONTRIBUINTE => 'Não contribuinte do ICMS',
    ];

    protected $table = 'destinatarios';

    protected $fillable = [
        'empresa_id', 'tipo_pessoa', 'cpf_cnpj', 'razao_social', 'nome_fantasia', 'inscricao_estadual',
        'ind_ie_dest', 'email', 'telefone', 'logradouro', 'numero', 'complemento', 'bairro',
        'codigo_municipio', 'municipio', 'uf', 'cep', 'codigo_pais',
    ];

    protected function casts(): array
    {
        return ['ind_ie_dest' => 'integer'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function isFisica(): bool
    {
        return $this->tipo_pessoa === self::FISICA;
    }

    public function documentoFormatado(): string
    {
        return $this->isFisica() ? Formata::cpf($this->cpf_cnpj) : Formata::cnpj($this->cpf_cnpj);
    }
}
