<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela oficial NCM (Siscomex). Só os códigos completos de 8 dígitos,
     * que são os únicos aceitos na NF-e.
     */
    public function up(): void
    {
        Schema::create('ncms', function (Blueprint $table) {
            $table->char('codigo', 8)->primary();
            $table->text('descricao');            // descrição do próprio item
            $table->text('descricao_completa');   // com as descrições dos níveis acima (capítulo, posição...)
            $table->text('busca');                // texto sem acentos e em minúsculas, para a pesquisa
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ncms');
    }
};
