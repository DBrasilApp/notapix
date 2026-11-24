<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_sefaz', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('nota_id')->nullable()->constrained('notas')->nullOnDelete();
            // autorizacao, retorno, consulta, evento, status_servico
            $table->string('servico', 30);
            $table->unsignedSmallInteger('ambiente');
            $table->text('xml_envio')->nullable();
            $table->text('xml_retorno')->nullable();
            $table->unsignedSmallInteger('codigo_status')->nullable();
            $table->string('motivo', 255)->nullable();
            $table->unsignedInteger('duracao_ms')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_sefaz');
    }
};
