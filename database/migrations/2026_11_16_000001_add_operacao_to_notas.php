<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            // venda, remessa, bonificacao, devolucao (ver Nota::OPERACOES)
            $table->string('operacao', 20)->default('venda')->after('status');
            // Documentos referenciados (NF-e pela chave ou nota modelo 1/1A)
            $table->jsonb('referencias')->nullable()->after('operacao');
            $table->decimal('valor_ipi_devolvido', 15, 2)->default(0)->after('valor_ipi');
        });

        Schema::table('nota_itens', function (Blueprint $table) {
            $table->decimal('base_icms_st', 15, 2)->default(0)->after('valor_icms');
            $table->decimal('aliq_icms_st', 5, 2)->default(0)->after('base_icms_st');
            $table->decimal('valor_icms_st', 15, 2)->default(0)->after('aliq_icms_st');
            $table->decimal('perc_devolvido', 5, 2)->nullable()->after('valor_icms_st');
            $table->decimal('valor_ipi_devolvido', 15, 2)->default(0)->after('perc_devolvido');
        });
    }

    public function down(): void
    {
        Schema::table('nota_itens', function (Blueprint $table) {
            $table->dropColumn(['base_icms_st', 'aliq_icms_st', 'valor_icms_st', 'perc_devolvido', 'valor_ipi_devolvido']);
        });
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn(['operacao', 'referencias', 'valor_ipi_devolvido']);
        });
    }
};
