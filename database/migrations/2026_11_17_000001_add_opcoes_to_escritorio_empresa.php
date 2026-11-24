<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escritorio_empresa', function (Blueprint $table) {
            // Quem pediu o vínculo: 'escritorio' (cadastrou o cliente) ou 'empresa' (autorizou o contador)
            $table->string('solicitado_por', 10)->default('escritorio')->after('status');
            // Quando a empresa fica sem créditos, pode usar os do escritório
            $table->boolean('usar_saldo_escritorio')->default(false)->after('solicitado_por');
        });
    }

    public function down(): void
    {
        Schema::table('escritorio_empresa', function (Blueprint $table) {
            $table->dropColumn(['solicitado_por', 'usar_saldo_escritorio']);
        });
    }
};
