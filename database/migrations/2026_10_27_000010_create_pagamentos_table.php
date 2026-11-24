<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pacote_id')->constrained('pacotes')->restrictOnDelete();
            // Carteira que recebe os créditos: App\Models\Empresa ou App\Models\Escritorio
            $table->morphs('carteira');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('valor', 10, 2);
            $table->string('gateway', 20)->default('mercadopago');
            $table->string('gateway_id', 100)->nullable()->unique();
            // pendente, pago, expirado, cancelado, estornado
            $table->string('status', 20)->default('pendente')->index();
            $table->text('pix_copia_cola')->nullable();
            $table->text('qr_code_base64')->nullable();
            $table->timestampTz('expira_em')->nullable();
            $table->timestampTz('pago_em')->nullable();
            $table->jsonb('webhook_dados')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos');
    }
};
