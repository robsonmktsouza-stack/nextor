<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('document_type', 8)->default('nfce');
            $table->char('origin_uf', 2)->default('BA');
            $table->char('destination_uf', 2)->default('BA');
            $table->string('crt', 4)->default('1');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('ncm_prefix', 8)->nullable();
            $table->string('cfop', 4);
            $table->string('csosn', 3);
            $table->string('pis_cst', 2);
            $table->string('cofins_cst', 2);
            $table->integer('priority')->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_active')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['document_type','origin_uf','destination_uf','crt','is_active'], 'fiscal_tax_rules_match_idx');
            $table->index(['product_id','ncm_prefix'], 'fiscal_tax_rules_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_rules');
    }
};
