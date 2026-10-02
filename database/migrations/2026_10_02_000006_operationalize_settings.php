<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->date('quote_expires_at')->nullable()->after('operation_date')->index();
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->string('source_key',120)->nullable()->unique()->after('created_by');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('tax_defaults')->nullable()->after('tax_group');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->json('tax_defaults')->nullable()->after('tax_group');
        });

        Schema::create('pdv_cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('opening_amount',14,2)->default(0);
            $table->decimal('closing_amount',14,2)->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamp('opened_at')->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id','closed_at']);
        });

        Schema::create('fiscal_document_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('document_type',16)->index();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status',24)->default('prepared')->index();
            $table->string('environment',24)->default('homologation');
            $table->unsignedInteger('series')->nullable();
            $table->unsignedBigInteger('document_number')->nullable();
            $table->json('settings_snapshot')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['document_type','sale_id'],'fiscal_job_type_sale_unique');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event',100)->index();
            $table->string('url',2000);
            $table->json('payload');
            $table->string('status',24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('fiscal_document_jobs');
        Schema::dropIfExists('pdv_cash_sessions');

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('tax_defaults');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tax_defaults');
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('quote_expires_at');
        });
    }
};
