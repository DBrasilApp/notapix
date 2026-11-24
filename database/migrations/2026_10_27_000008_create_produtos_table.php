<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo', 60);
            $table->string('descricao', 120);
            $table->string('gtin', 14)->default('SEM GTIN');
            $table->char('ncm', 8);
            $table->char('cest', 7)->nullable();
            $table->string('unidade', 6)->default('UN');
            $table->decimal('valor_unitario', 15, 4)->default(0);

            // Tributação padrão sugerida na nota
            $table->unsignedSmallInteger('origem')->default(0);
            $table->char('cfop_padrao', 4)->nullable();
            $table->string('cst_csosn', 3)->nullable();
            $table->decimal('aliq_icms', 5, 2)->nullable();
            $table->char('cst_pis', 2)->nullable();
            $table->char('cst_cofins', 2)->nullable();
            // Reforma tributária (IBS/CBS)
            $table->char('cst_ibs_cbs', 3)->nullable();
            $table->char('cclass_trib', 6)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produtos');
    }
};
