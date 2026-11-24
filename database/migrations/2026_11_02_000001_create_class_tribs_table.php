<?php

use App\Services\ImportadorClassTrib;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela oficial de Classificação Tributária do IBS/CBS (cClassTrib), com o CST de cada uma
        Schema::create('class_tribs', function (Blueprint $table) {
            $table->char('codigo', 6)->primary();
            $table->char('cst', 3)->index();
            $table->string('nome_cst');
            $table->text('descricao');
            $table->string('nome_reduzido')->nullable();
            $table->text('busca');                         // sem acentos, para a pesquisa
            $table->decimal('perc_red_ibs', 7, 4)->default(0);
            $table->decimal('perc_red_cbs', 7, 4)->default(0);
            $table->boolean('ind_nfe')->default(false);     // pode ser usada em NF-e
            $table->unsignedTinyInteger('tipo_aliquota')->nullable();
            $table->string('url_legislacao')->nullable();
            $table->date('inicio_vigencia')->nullable();
            $table->date('fim_vigencia')->nullable();
            $table->timestamps();
        });

        // Já carrega a tabela que acompanha o sistema (database/data/cclasstrib.json)
        app(ImportadorClassTrib::class)->importar(ImportadorClassTrib::lerArquivo());
    }

    public function down(): void
    {
        Schema::dropIfExists('class_tribs');
    }
};
