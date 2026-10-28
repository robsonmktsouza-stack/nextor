<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fiscal_tax_rules', function (Blueprint $table) {
            $table->foreignId('operation_nature_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_tax_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('origin_municipality', 7)->nullable();
            $table->string('destination_municipality', 7)->nullable();
            $table->string('recipient_icms_indicator', 1)->nullable();
            $table->string('catalog_version', 32)->nullable();
            $table->string('catalog_status', 16)->nullable();
            $table->timestamp('fiscal_reviewed_at')->nullable();
            $table->foreignId('fiscal_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['document_type', 'origin_uf', 'destination_uf', 'crt', 'is_active'], 'fiscal_rule_determination_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_tax_rules', function (Blueprint $table) {
            $table->dropForeign(['operation_nature_id']);
            $table->dropForeign(['fiscal_tax_group_id']);
            $table->dropForeign(['fiscal_reviewed_by']);
            $table->dropIndex('fiscal_rule_determination_idx');
            $table->dropColumn(['operation_nature_id', 'fiscal_tax_group_id', 'origin_municipality',
                'destination_municipality', 'recipient_icms_indicator', 'catalog_version', 'catalog_status',
                'fiscal_reviewed_at', 'fiscal_reviewed_by']);
        });
    }
};
