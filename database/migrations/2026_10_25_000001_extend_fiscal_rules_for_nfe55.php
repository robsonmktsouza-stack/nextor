<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fiscal_tax_rules', function (Blueprint $table) {
            $table->string('input_cfop', 4)->nullable();
            $table->string('cest', 7)->nullable();
            $table->string('origin', 1)->nullable();
            $table->string('operation_type', 20)->nullable();
            $table->string('fiscal_operation', 32)->nullable();
            $table->string('purpose', 30)->nullable();
            $table->string('recipient_profile', 32)->nullable();
            $table->string('tax_regime', 40)->nullable();
            $table->boolean('final_consumer')->nullable();
            $table->string('icms_cst', 2)->nullable();
            $table->string('fiscal_benefit_code', 10)->nullable();
            $table->json('tax_parameters')->nullable();
            $table->text('legal_source')->nullable();
            $table->string('legal_reference', 255)->nullable();
            $table->string('layout_version', 80)->nullable();
            $table->date('consulted_at')->nullable();
            $table->text('application_conditions')->nullable();
        });
        Schema::create('fiscal_tax_rule_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_tax_rule_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['fiscal_tax_rule_id', 'revision'], 'fiscal_rule_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_rule_revisions');
        Schema::table('fiscal_tax_rules', function (Blueprint $table) {
            $table->dropColumn(['input_cfop', 'cest', 'origin', 'operation_type', 'fiscal_operation', 'purpose',
                'recipient_profile', 'tax_regime', 'final_consumer', 'icms_cst', 'fiscal_benefit_code', 'tax_parameters', 'legal_source',
                'legal_reference', 'layout_version', 'consulted_at', 'application_conditions']);
        });
    }
};
