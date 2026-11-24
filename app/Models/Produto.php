<?php

namespace App\Models;

use App\Support\Formata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Produto extends Model
{
    use SoftDeletes;

    /** Sugestões para empresa do Simples Nacional / MEI vendendo mercadoria de terceiros. */
    public const PADROES_SIMPLES = [
        'unidade' => 'UN',
        'gtin' => 'SEM GTIN',
        'origem' => 0,
        'cfop_padrao' => '5102',
        'cst_csosn' => '102',
        'cst_pis' => '49',
        'cst_cofins' => '49',
    ];

    public const CSOSN = [
        '101' => '101 - Tributada com permissão de crédito',
        '102' => '102 - Tributada sem permissão de crédito',
        '103' => '103 - Isenção do ICMS para faixa de receita bruta',
        '201' => '201 - Com permissão de crédito e com cobrança do ICMS por ST',
        '202' => '202 - Sem permissão de crédito e com cobrança do ICMS por ST',
        '203' => '203 - Isenção para faixa de receita bruta e com cobrança do ICMS por ST',
        '300' => '300 - Imune',
        '400' => '400 - Não tributada',
        '500' => '500 - ICMS cobrado anteriormente por ST ou antecipação',
        '900' => '900 - Outros',
    ];

    public const ORIGENS = [
        0 => '0 - Nacional',
        1 => '1 - Estrangeira, importação direta',
        2 => '2 - Estrangeira, adquirida no mercado interno',
        3 => '3 - Nacional, conteúdo de importação entre 40% e 70%',
        4 => '4 - Nacional, processos produtivos básicos',
        5 => '5 - Nacional, conteúdo de importação até 40%',
        6 => '6 - Estrangeira, importação direta, sem similar nacional',
        7 => '7 - Estrangeira, mercado interno, sem similar nacional',
        8 => '8 - Nacional, conteúdo de importação acima de 70%',
    ];

    /** CST de PIS e COFINS para operações de saída. */
    public const CST_PIS_COFINS = [
        '01' => '01 - Tributável, alíquota básica',
        '02' => '02 - Tributável, alíquota diferenciada',
        '03' => '03 - Tributável, alíquota por unidade de medida',
        '04' => '04 - Monofásica, revenda a alíquota zero',
        '05' => '05 - Substituição tributária',
        '06' => '06 - Alíquota zero',
        '07' => '07 - Isenta',
        '08' => '08 - Sem incidência',
        '09' => '09 - Com suspensão',
        '49' => '49 - Outras operações de saída',
        '99' => '99 - Outras operações',
    ];

    /** Unidades comerciais aceitas no cadastro (campo uCom da NF-e). */
    public const UNIDADES = [
        'UN' => 'Unidade',
        'CX' => 'Caixa',
        'FD' => 'Fardo',
        'PC' => 'Peça',
        'M2' => 'Metro quadrado',
        'M3' => 'Metro cúbico',
        'M' => 'Metro linear',
        'KG' => 'Quilograma',
        'LT' => 'Litro',
        'DZ' => 'Dúzia',
        'PT' => 'Pacote',
        'BD' => 'Balde',
        'GL' => 'Galão',
        'RL' => 'Rolo',
        'JG' => 'Jogo',
        'KT' => 'Kit',
    ];

    protected $table = 'produtos';

    protected $fillable = [
        'empresa_id', 'codigo', 'descricao', 'gtin', 'ncm', 'cest', 'unidade', 'valor_unitario',
        'origem', 'cfop_padrao', 'cst_csosn', 'aliq_icms', 'cst_pis', 'cst_cofins',
        'cst_ibs_cbs', 'cclass_trib',
    ];

    protected function casts(): array
    {
        return [
            'valor_unitario' => 'decimal:4',
            'aliq_icms' => 'decimal:2',
            'origem' => 'integer',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function ncmOficial(): BelongsTo
    {
        return $this->belongsTo(Ncm::class, 'ncm', 'codigo');
    }

    /** Preço para mostrar: 2 casas, ou mais se o valor tiver centavos fracionados. */
    public function precoFormatado(): string
    {
        $valor = (float) $this->valor_unitario;
        $casas = round($valor, 2) == $valor ? 2 : 4;

        return Formata::moeda($valor, $casas);
    }
}
