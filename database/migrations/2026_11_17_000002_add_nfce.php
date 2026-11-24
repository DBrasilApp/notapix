<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            // NFC-e (modelo 65): CSC e ID do CSC por ambiente (guardados criptografados), série e numeração próprias
            $table->text('nfce_csc_homologacao')->nullable();
            $table->string('nfce_csc_id_homologacao', 6)->nullable();
            $table->text('nfce_csc_producao')->nullable();
            $table->string('nfce_csc_id_producao', 6)->nullable();
            $table->unsignedSmallInteger('nfce_serie')->default(1);
            $table->unsignedInteger('nfce_proximo_numero')->default(1);
            $table->unsignedTinyInteger('nfce_bobina')->default(80); // largura do papel em mm
        });

        Schema::table('notas', function (Blueprint $table) {
            // NFC-e: várias formas de pagamento na mesma venda e o troco
            $table->jsonb('pagamentos')->nullable()->after('descricao_pagamento');
            $table->decimal('troco', 15, 2)->default(0)->after('pagamentos');
        });
    }

    public function down(): void
    {
        Schema::table('notas', fn (Blueprint $table) => $table->dropColumn(['pagamentos', 'troco']));
        Schema::table('empresas', fn (Blueprint $table) => $table->dropColumn([
            'nfce_csc_homologacao', 'nfce_csc_id_homologacao', 'nfce_csc_producao', 'nfce_csc_id_producao',
            'nfce_serie', 'nfce_proximo_numero', 'nfce_bobina',
        ]));
    }
};
