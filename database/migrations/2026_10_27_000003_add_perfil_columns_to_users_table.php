<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin, escritorio ou empresa (ver App\Enums\Perfil)
            $table->string('perfil', 20)->default('empresa')->after('password')->index();
            $table->foreignId('escritorio_id')->nullable()->after('perfil')->constrained('escritorios')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->after('escritorio_id')->constrained('empresas')->nullOnDelete();
            $table->boolean('ativo')->default(true)->after('empresa_id');
            $table->timestamp('ultimo_acesso_em')->nullable()->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escritorio_id');
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropColumn(['perfil', 'ativo', 'ultimo_acesso_em']);
        });
    }
};
