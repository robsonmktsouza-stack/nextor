<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_companies', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name', 190);
            $table->string('trade_name', 190)->nullable();
            $table->string('cnpj', 14)->unique();
            $table->string('state_registration', 30);
            $table->string('crt', 2);
            $table->char('uf', 2);
            $table->string('city_ibge', 7);
            $table->string('street', 190);
            $table->string('number', 30);
            $table->string('complement', 120)->nullable();
            $table->string('district', 120);
            $table->string('city', 120);
            $table->string('zip_code', 8);
            $table->char('environment', 1)->default('2');
            $table->boolean('production_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['uf', 'environment']);
        });

        Schema::create('fiscal_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_company_id')->unique()->constrained('fiscal_companies')->cascadeOnDelete();
            $table->longText('pfx_payload');
            $table->text('pfx_password');
            $table->string('subject', 500)->nullable();
            $table->string('issuer', 500)->nullable();
            $table->string('serial_number', 160)->nullable();
            $table->string('fingerprint_sha256', 95)->nullable()->index();
            $table->string('subject_document', 20)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fiscal_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_company_id')->constrained('fiscal_companies')->cascadeOnDelete();
            $table->char('model', 2)->default('65');
            $table->unsignedSmallInteger('series')->default(1);
            $table->char('environment', 1)->default('2');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedBigInteger('last_reserved_number')->nullable();
            $table->timestamps();

            $table->unique(
                ['fiscal_company_id', 'model', 'series', 'environment'],
                'fiscal_sequences_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_sequences');
        Schema::dropIfExists('fiscal_certificates');
        Schema::dropIfExists('fiscal_companies');
    }
};
