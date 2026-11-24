<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nota_id')->constrained('notas')->cascadeOnDelete();
            $table->foreignId('produto_id')->nullable()->constrained('produtos')->nullOnDelete();
            $table->unsignedSmallInteger('numero_item');

            // Cópia do produto no momento da emissão
            $table->string('codigo', 60);
            $table->string('descricao', 120);
            $table->string('gtin', 14)->default('SEM GTIN');
            $table->char('ncm', 8);
            $table->char('cest', 7)->nullable();
            $table->char('cfop', 4);
            $table->string('unidade', 6);

            $table->decimal('quantidade', 15, 4);
            $table->decimal('valor_unitario', 15, 4);
            $table->decimal('valor_total', 15, 2);
            $table->decimal('valor_desconto', 15, 2)->default(0);
            $table->decimal('valor_frete', 15, 2)->default(0);

            // ICMS
            $table->unsignedSmallInteger('origem')->default(0);
            $table->string('cst_csosn', 3)->nullable();
            $table->decimal('base_icms', 15, 2)->default(0);
            $table->decimal('aliq_icms', 5, 2)->default(0);
            $table->decimal('valor_icms', 15, 2)->default(0);

            // PIS e COFINS
            $table->char('cst_pis', 2)->nullable();
            $table->decimal('valor_pis', 15, 2)->default(0);
            $table->char('cst_cofins', 2)->nullable();
            $table->decimal('valor_cofins', 15, 2)->default(0);

            // IBS/CBS - grupo UB da NT 2025.002-RTC (conferir versão vigente)
            $table->char('cst_ibs_cbs', 3)->nullable();
            $table->char('cclass_trib', 6)->nullable();
            $table->decimal('base_ibs_cbs', 15, 2)->default(0);
            $table->decimal('aliq_ibs_uf', 7, 4)->default(0);
            $table->decimal('valor_ibs_uf', 15, 2)->default(0);
            $table->decimal('aliq_ibs_mun', 7, 4)->default(0);
            $table->decimal('valor_ibs_mun', 15, 2)->default(0);
            $table->decimal('aliq_cbs', 7, 4)->default(0);
            $table->decimal('valor_cbs', 15, 2)->default(0);
            $table->jsonb('tributos_extras')->nullable();

            $table->timestamps();

            $table->unique(['nota_id', 'numero_item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_itens');
    }
};
