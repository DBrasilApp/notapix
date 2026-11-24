<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nota_id')->constrained('notas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // cancelamento ou carta_correcao
            $table->string('tipo', 20);
            $table->unsignedSmallInteger('sequencia')->default(1);
            $table->text('texto');
            // na_fila, registrado, rejeitado
            $table->string('status', 20)->default('na_fila');
            $table->string('protocolo', 20)->nullable();
            $table->unsignedSmallInteger('codigo_status')->nullable();
            $table->string('motivo', 255)->nullable();
            $table->string('xml_path', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_eventos');
    }
};
