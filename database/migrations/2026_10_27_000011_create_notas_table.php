<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('destinatario_id')->nullable()->constrained('destinatarios')->nullOnDelete();
            $table->jsonb('destinatario_dados')->nullable(); // cópia do destinatário na emissão
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedSmallInteger('modelo')->default(55);
            $table->unsignedSmallInteger('serie')->default(1);
            $table->unsignedInteger('numero')->nullable();
            $table->char('chave', 44)->nullable()->unique();
            $table->unsignedSmallInteger('ambiente')->default(2);
            // rascunho, na_fila, processando, autorizada, rejeitada, denegada, cancelada
            $table->string('status', 20)->default('rascunho')->index();

            $table->string('natureza_operacao', 60)->default('Venda de mercadoria');
            $table->unsignedSmallInteger('tipo_operacao')->default(1); // 0 entrada, 1 saída
            $table->unsignedSmallInteger('finalidade')->default(1);    // 1 normal, 2 compl., 3 ajuste, 4 devolução
            $table->unsignedSmallInteger('id_dest')->default(1);       // 1 interna, 2 interestadual, 3 exterior
            $table->unsignedSmallInteger('ind_final')->default(0);
            $table->unsignedSmallInteger('ind_pres')->default(1);
            $table->timestampTz('data_emissao')->nullable();
            $table->timestampTz('data_saida')->nullable();
            $table->unsignedSmallInteger('modalidade_frete')->default(9);

            $table->decimal('valor_produtos', 15, 2)->default(0);
            $table->decimal('valor_desconto', 15, 2)->default(0);
            $table->decimal('valor_frete', 15, 2)->default(0);
            $table->decimal('valor_seguro', 15, 2)->default(0);
            $table->decimal('valor_outras', 15, 2)->default(0);
            $table->decimal('valor_icms', 15, 2)->default(0);
            $table->decimal('valor_icms_st', 15, 2)->default(0);
            $table->decimal('valor_ipi', 15, 2)->default(0);
            $table->decimal('valor_pis', 15, 2)->default(0);
            $table->decimal('valor_cofins', 15, 2)->default(0);
            $table->decimal('valor_ibs', 15, 2)->default(0);
            $table->decimal('valor_cbs', 15, 2)->default(0);
            $table->decimal('valor_total', 15, 2)->default(0);
            $table->text('informacoes_complementares')->nullable();

            $table->string('recibo', 20)->nullable();
            $table->string('protocolo', 20)->nullable();
            $table->unsignedSmallInteger('codigo_status')->nullable(); // cStat
            $table->string('motivo', 255)->nullable();                // xMotivo
            $table->timestampTz('autorizada_em')->nullable();
            $table->string('xml_path', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'modelo', 'serie', 'numero', 'ambiente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas');
    }
};
