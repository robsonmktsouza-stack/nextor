<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_company_id')->constrained('fiscal_companies')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();

            $table->char('model', 2)->default('65');
            $table->unsignedSmallInteger('series');
            $table->unsignedBigInteger('number');
            $table->char('access_key', 44)->nullable()->unique();
            $table->char('environment', 1);
            $table->unsignedTinyInteger('emission_type')->default(1);
            $table->char('numeric_code', 8);
            $table->string('state', 24)->index();
            $table->string('layout_version', 10)->default('4.00');
            $table->timestamp('issue_at');

            $table->string('c_stat', 8)->nullable();
            $table->string('x_motivo', 500)->nullable();
            $table->string('protocol', 80)->nullable();
            $table->timestamp('authorized_at')->nullable();

            $table->longText('snapshot_payload');
            $table->char('snapshot_sha256', 64);

            $table->longText('xml_generated')->nullable();
            $table->longText('xml_signed')->nullable();
            $table->longText('xml_protocolled')->nullable();

            $table->timestamp('contingency_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['fiscal_company_id', 'model', 'series', 'number', 'environment'],
                'fiscal_documents_number_scope_unique'
            );
            $table->index('sale_id', 'fiscal_documents_sale_idx');
            $table->index(['fiscal_company_id', 'state'], 'fiscal_documents_company_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
