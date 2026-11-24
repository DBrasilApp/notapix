<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('razao_social', 150);
            $table->string('nome_fantasia', 150)->nullable();
            $table->char('cnpj', 14)->unique();
            $table->string('inscricao_estadual', 20)->nullable();
            $table->string('inscricao_municipal', 20)->nullable();
            // 1 Simples Nacional, 2 Simples com excesso de sublimite, 3 Regime normal, 4 MEI
            $table->unsignedSmallInteger('crt')->default(1);
            $table->string('cnae', 7)->nullable();

            $table->string('logradouro', 60)->nullable();
            $table->string('numero', 60)->nullable();
            $table->string('complemento', 60)->nullable();
            $table->string('bairro', 60)->nullable();
            $table->char('codigo_municipio', 7)->nullable();
            $table->string('municipio', 60)->nullable();
            $table->char('uf', 2)->default('ES');
            $table->char('cep', 8)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('email', 150)->nullable();

            // 1 produção, 2 homologação
            $table->unsignedSmallInteger('ambiente')->default(2);
            $table->unsignedSmallInteger('serie_nfe')->default(1);
            $table->unsignedInteger('proximo_numero_nfe')->default(1);

            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
