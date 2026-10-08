<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('fiscal_tax_group_id')->nullable()->constrained('fiscal_tax_groups')->nullOnDelete();
        });
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('fiscal_tax_group_id')->nullable()->constrained('fiscal_tax_groups')->nullOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_tax_group_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_tax_group_id');
        });
    }
};
