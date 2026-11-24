<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NFS-e Nacional (notas de serviço): configuração da empresa, serviços, notas e clientes mensais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            // Série própria do NotaPix: não pode repetir a série usada no portal do governo
            $table->string('nfse_serie', 5)->default('50');
            $table->unsignedBigInteger('nfse_proximo_numero')->default(1);
            // Simples (ME/EPP): 1 = federais e ISS pelo Simples; 2 = federais pelo Simples e ISS fora; 3 = tudo fora
            $table->unsignedTinyInteger('nfse_regime_apuracao')->default(1);
            // % aproximado de tributos (alíquota efetiva do Simples): vai em toda NFS-e de ME/EPP
            $table->decimal('nfse_aliquota_simples', 5, 2)->nullable();
            // Alíquota do ISS da faixa do Simples: só vai na nota quando o tomador retém o ISS
            $table->decimal('nfse_aliquota_iss', 5, 2)->nullable();
        });

        Schema::create('servicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo', 20);
            $table->string('nome', 120);                     // nome curto, só para o usuário
            $table->char('ctribnac', 6);                     // código de tributação nacional (LC 116)
            $table->string('ctribmun', 3)->nullable();       // desdobro municipal (quando o município usa)
            $table->char('cnbs', 9)->nullable();             // item da NBS
            $table->text('discriminacao');                   // texto padrão que vai na nota
            $table->decimal('valor', 15, 2)->nullable();
            $table->boolean('iss_retido')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo']);
        });

        // Clientes que recebem a mesma NFS-e todo mês (emissão em lote)
        Schema::create('nfse_contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('destinatario_id')->constrained('destinatarios')->cascadeOnDelete();
            $table->foreignId('servico_id')->constrained('servicos')->cascadeOnDelete();
            $table->decimal('valor', 15, 2);
            $table->text('discriminacao')->nullable();       // vazio = usa o texto do serviço
            $table->boolean('iss_retido')->default(false);
            $table->jsonb('retencoes')->nullable();          // inss, irrf, csll, pis, cofins (%)
            $table->boolean('ativo')->default(true);
            $table->date('reajustado_em')->nullable();
            $table->decimal('valor_anterior', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('nfses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('servico_id')->nullable()->constrained('servicos')->nullOnDelete();
            $table->foreignId('destinatario_id')->nullable()->constrained('destinatarios')->nullOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('nfse_contratos')->nullOnDelete();
            $table->jsonb('tomador_dados')->nullable();      // cópia do tomador no momento da emissão
            $table->string('lote', 36)->nullable()->index();

            $table->unsignedSmallInteger('ambiente');
            $table->string('serie', 5)->nullable();
            $table->unsignedBigInteger('numero_dps')->nullable();
            $table->string('id_dps', 45)->nullable()->unique();
            $table->date('competencia');

            $table->char('ctribnac', 6);
            $table->string('ctribmun', 3)->nullable();
            $table->char('cnbs', 9)->nullable();
            $table->char('municipio_prestacao', 7);
            $table->text('discriminacao');
            $table->text('informacoes_complementares')->nullable();

            $table->decimal('valor_servico', 15, 2);
            $table->boolean('iss_retido')->default(false);
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->decimal('valor_iss_retido', 15, 2)->default(0);
            $table->decimal('ret_inss', 15, 2)->default(0);
            $table->decimal('ret_irrf', 15, 2)->default(0);
            $table->decimal('ret_csll', 15, 2)->default(0);
            $table->decimal('ret_pis', 15, 2)->default(0);
            $table->decimal('ret_cofins', 15, 2)->default(0);
            $table->decimal('valor_liquido', 15, 2);

            // rascunho, processando, autorizada, rejeitada, cancelada
            $table->string('status', 20)->default('rascunho')->index();
            $table->string('codigo_status', 10)->nullable(); // ex.: E0312
            $table->text('motivo')->nullable();
            $table->char('chave', 50)->nullable()->unique();
            $table->string('numero_nfse', 15)->nullable();
            $table->timestamp('autorizada_em')->nullable();
            $table->string('dps_path')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('credito_reserva_id')->nullable()->constrained('creditos_movimentos')->nullOnDelete();
            $table->unsignedSmallInteger('tentativas')->default(0);

            $table->timestamp('cancelada_em')->nullable();
            $table->string('cancelamento_motivo', 255)->nullable();
            $table->string('cancelamento_xml_path')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'competencia']);
        });

        Schema::table('creditos_movimentos', function (Blueprint $table) {
            $table->foreignId('nfse_id')->nullable()->after('nota_id')->constrained('nfses')->nullOnDelete();
        });

        Schema::table('logs_sefaz', function (Blueprint $table) {
            $table->foreignId('nfse_id')->nullable()->after('nota_id')->constrained('nfses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logs_sefaz', fn (Blueprint $table) => $table->dropConstrainedForeignId('nfse_id'));
        Schema::table('creditos_movimentos', fn (Blueprint $table) => $table->dropConstrainedForeignId('nfse_id'));
        Schema::dropIfExists('nfses');
        Schema::dropIfExists('nfse_contratos');
        Schema::dropIfExists('servicos');
        Schema::table('empresas', fn (Blueprint $table) => $table->dropColumn([
            'nfse_serie', 'nfse_proximo_numero', 'nfse_regime_apuracao', 'nfse_aliquota_simples', 'nfse_aliquota_iss',
        ]));
    }
};
