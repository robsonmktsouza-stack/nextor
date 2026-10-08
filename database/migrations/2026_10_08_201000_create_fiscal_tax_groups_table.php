<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_tax_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('kind', 10);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->string('cfop_pattern', 4)->nullable();
            $table->string('nfce_csosn', 3)->nullable();
            $table->string('icms_csosn', 3)->nullable();
            $table->string('icms_cst', 2)->nullable();
            $table->string('pis_cst', 2)->nullable();
            $table->string('cofins_cst', 2)->nullable();
            $table->string('ipi_cst', 2)->nullable();
            $table->string('iss_exigibility', 2)->nullable();
            $table->json('tax_config')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['kind','is_active','is_default'],'tax_groups_defaults_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_groups');
    }
};
