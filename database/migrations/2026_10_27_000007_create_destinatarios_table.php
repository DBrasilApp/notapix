<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinatarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->char('tipo_pessoa', 1); // F ou J
            $table->string('cpf_cnpj', 14);
            $table->string('razao_social', 150);
            $table->string('inscricao_estadual', 20)->nullable();
            // 1 contribuinte ICMS, 2 isento, 9 não contribuinte
            $table->unsignedSmallInteger('ind_ie_dest')->default(9);
            $table->string('email', 150)->nullable();
            $table->string('telefone', 20)->nullable();

            $table->string('logradouro', 60)->nullable();
            $table->string('numero', 60)->nullable();
            $table->string('complemento', 60)->nullable();
            $table->string('bairro', 60)->nullable();
            $table->char('codigo_municipio', 7)->nullable();
            $table->string('municipio', 60)->nullable();
            $table->char('uf', 2)->nullable();
            $table->char('cep', 8)->nullable();
            $table->char('codigo_pais', 4)->default('1058');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'cpf_cnpj']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinatarios');
    }
};
