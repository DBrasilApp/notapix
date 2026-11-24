<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creditos_movimentos', function (Blueprint $table) {
            $table->id();
            // Dono dos créditos: App\Models\Empresa ou App\Models\Escritorio
            $table->morphs('carteira');
            // compra, cortesia, reserva, estorno, expiracao, ajuste
            $table->string('tipo', 20);
            $table->integer('quantidade'); // positiva entra, negativa sai
            $table->foreignId('nota_id')->nullable()->constrained('notas')->nullOnDelete();
            $table->foreignId('pagamento_id')->nullable()->constrained('pagamentos')->nullOnDelete();
            $table->date('validade_ate')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observacao', 255)->nullable();
            $table->timestamps();

            $table->index(['carteira_type', 'carteira_id', 'tipo']);
        });

        Schema::table('notas', function (Blueprint $table) {
            $table->foreignId('credito_reserva_id')->nullable()->after('pdf_path')
                ->constrained('creditos_movimentos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credito_reserva_id');
        });
        Schema::dropIfExists('creditos_movimentos');
    }
};
