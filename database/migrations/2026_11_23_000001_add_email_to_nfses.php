<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Envio do XML e do PDF da NFS-e por e-mail. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfses', function (Blueprint $table) {
            $table->timestamp('email_enviado_em')->nullable();
            $table->string('email_destinos', 500)->nullable();
            $table->string('email_erro', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfses', fn (Blueprint $table) => $table->dropColumn(['email_enviado_em', 'email_destinos', 'email_erro']));
    }
};
