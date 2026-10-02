<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_transmissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_company_id')
                ->constrained('fiscal_companies')
                ->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')
                ->nullable()
                ->constrained('fiscal_documents')
                ->nullOnDelete();

            $table->string('service', 60);
            $table->char('environment', 1);
            $table->char('uf', 2);
            $table->string('authorizer', 40);
            $table->string('endpoint_key', 120);

            $table->uuid('attempt_uuid')->unique();

            $table->dateTimeTz('started_at');
            $table->dateTimeTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('transport_status', 40);
            $table->string('fiscal_status', 40)->nullable();

            $table->string('c_stat', 10)->nullable();
            $table->string('x_motivo', 255)->nullable();

            $table->char('request_sha256', 64)->nullable();
            $table->char('response_sha256', 64)->nullable();

            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();

            $table->string('error_class', 190)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['fiscal_company_id', 'service', 'started_at'], 'fiscal_transmission_company_service_idx');
            $table->index(['fiscal_document_id', 'service'], 'fiscal_transmission_document_service_idx');
            $table->index(['uf', 'environment', 'service'], 'fiscal_transmission_route_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_transmissions');
    }
};
