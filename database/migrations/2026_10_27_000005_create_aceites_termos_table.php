<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aceites_termos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // termos_uso ou privacidade
            $table->string('documento', 30);
            $table->string('versao', 10);
            $table->ipAddress('ip')->nullable();
            $table->timestamp('aceito_em');
            $table->timestamps();

            $table->index(['user_id', 'documento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aceites_termos');
    }
};
