<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador autônomo trabalha com CPF: o documento do escritório passa a aceitar CPF (11) ou CNPJ (14).
 * A coluna continua se chamando "cnpj"; de tamanho fixo (char) vira variável, para o CPF não ganhar espaços.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escritorios', function (Blueprint $table) {
            $table->string('cnpj', 14)->change();
        });
    }

    public function down(): void
    {
        Schema::table('escritorios', function (Blueprint $table) {
            $table->char('cnpj', 14)->change();
        });
    }
};
