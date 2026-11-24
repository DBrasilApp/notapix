<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escritorio_empresa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escritorio_id')->constrained('escritorios')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // pendente, ativo ou revogado
            $table->string('status', 20)->default('pendente');
            $table->timestamp('autorizado_em')->nullable();
            $table->timestamp('revogado_em')->nullable();
            $table->timestamps();

            $table->unique(['escritorio_id', 'empresa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escritorio_empresa');
    }
};
