<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacotes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60);
            $table->unsignedInteger('quantidade_creditos');
            $table->decimal('preco', 10, 2);
            $table->unsignedInteger('validade_dias')->default(365);
            // todos ou escritorio
            $table->string('publico', 20)->default('todos');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacotes');
    }
};
