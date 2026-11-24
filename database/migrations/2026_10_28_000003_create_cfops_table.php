<?php

use App\Services\ImportadorCfop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfops', function (Blueprint $table) {
            $table->char('codigo', 4)->primary();
            $table->text('titulo');
            $table->text('descricao')->nullable();      // nota explicativa oficial
            $table->text('busca');                      // sem acentos, para a pesquisa
            $table->boolean('ind_nfe')->default(true);  // pode ser usado em NF-e
            $table->boolean('ind_devolucao')->default(false);
            $table->boolean('ind_retorno')->default(false);
            $table->boolean('ind_remessa')->default(false);
            $table->boolean('ind_anulacao')->default(false);
            $table->boolean('ind_combustivel')->default(false);
            $table->boolean('ind_exclui_ibs_cbs')->default(false);
            $table->date('inicio_vigencia')->nullable();
            $table->timestamps();
        });

        // Já carrega a tabela oficial que acompanha o sistema (database/data/cfop.json)
        app(ImportadorCfop::class)->importar();
    }

    public function down(): void
    {
        Schema::dropIfExists('cfops');
    }
};
