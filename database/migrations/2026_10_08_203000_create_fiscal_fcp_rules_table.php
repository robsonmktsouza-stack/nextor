<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_fcp_rules',function(Blueprint $table) {
            $table->id();
            $table->char('uf',2);
            $table->string('ncm_prefix',8)->nullable();
            $table->decimal('rate',8,4);
            $table->boolean('apply_to_own_fcp')->default(false);
            $table->boolean('is_active')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['uf','is_active','ncm_prefix'],'fcp_uf_ncm_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('fiscal_fcp_rules');
    }
};
