<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdv_suspended_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label',190)->nullable();
            $table->json('payload');
            $table->decimal('total',14,2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('suspended_at')->index();
            $table->timestamp('resumed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id','resumed_at']);
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('integration_type',1)->nullable()->after('payment_method');
            $table->string('transaction_document',14)->nullable()->after('integration_type');
            $table->string('transaction_state',2)->nullable()->after('transaction_document');
            $table->string('institution_document',14)->nullable()->after('transaction_state');
            $table->string('card_brand',2)->nullable()->after('institution_document');
            $table->string('authorization_code',128)->nullable()->after('card_brand');
            $table->string('beneficiary_document',14)->nullable()->after('authorization_code');
            $table->string('terminal_id',40)->nullable()->after('beneficiary_document');
        });

        Schema::table('fiscal_document_jobs', function (Blueprint $table) {
            $table->string('emission_mode',20)->default('normal')->after('status')->index();
            $table->string('contingency_reason',255)->nullable()->after('emission_mode');
            $table->timestamp('contingency_started_at')->nullable()->after('contingency_reason');
            $table->string('access_key',44)->nullable()->after('document_number')->index();
            $table->string('protocol',40)->nullable()->after('access_key');
            $table->timestamp('authorized_at')->nullable()->after('protocol');
            $table->string('cancellation_status',24)->nullable()->after('authorized_at')->index();
            $table->string('cancellation_reason',255)->nullable()->after('cancellation_status');
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancellation_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'emission_mode','contingency_reason','contingency_started_at',
                'access_key','protocol','authorized_at','cancellation_status',
                'cancellation_reason','cancellation_requested_at','cancelled_at',
            ]);
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn([
                'integration_type','transaction_document','transaction_state',
                'institution_document','card_brand','authorization_code',
                'beneficiary_document','terminal_id',
            ]);
        });

        Schema::dropIfExists('pdv_suspended_sales');
    }
};
