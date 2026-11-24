<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            // Forma de pagamento (tPag da NF-e): 01 dinheiro, 17 PIX, 15 boleto...
            $table->char('forma_pagamento', 2)->default('01')->after('modalidade_frete');
            // 0 à vista, 1 a prazo
            $table->unsignedSmallInteger('ind_pagamento')->default(0)->after('forma_pagamento');
            // Obrigatória quando a forma é 99 (outros)
            $table->string('descricao_pagamento', 60)->nullable()->after('ind_pagamento');
            $table->unsignedSmallInteger('tentativas')->default(0)->after('motivo');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn(['forma_pagamento', 'ind_pagamento', 'descricao_pagamento', 'tentativas']);
        });
    }
};
