<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_tax_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 190);
            $table->char('crt', 1);
            $table->char('model', 2)->default('65');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['crt', 'model', 'is_active'], 'fiscal_tax_groups_scope_idx');
        });

        Schema::create('fiscal_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_tax_group_id')
                ->constrained('fiscal_tax_groups')
                ->cascadeOnDelete();

            $table->string('operation_scope', 80);
            $table->char('cfop', 4);
            $table->char('icms_csosn', 3);

            $table->char('pis_cst', 2);
            $table->decimal('pis_rate', 7, 4);
            $table->string('pis_base_mode', 40);

            $table->char('cofins_cst', 2);
            $table->decimal('cofins_rate', 7, 4);
            $table->string('cofins_base_mode', 40);

            $table->string('rtc_mode', 24);
            $table->char('ibs_cst', 3)->nullable();
            $table->char('ibs_classification', 6)->nullable();
            $table->decimal('ibs_uf_rate', 7, 4)->nullable();
            $table->decimal('ibs_mun_rate', 7, 4)->nullable();
            $table->decimal('cbs_rate', 7, 4)->nullable();
            $table->string('ibs_cbs_base_mode', 40)->nullable();

            $table->string('rule_version', 40);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['fiscal_tax_group_id', 'operation_scope', 'effective_from', 'rule_version'],
                'fiscal_tax_rules_version_unique'
            );
            $table->index(
                ['fiscal_tax_group_id', 'operation_scope', 'is_active'],
                'fiscal_tax_rules_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_rules');
        Schema::dropIfExists('fiscal_tax_groups');
    }
};
