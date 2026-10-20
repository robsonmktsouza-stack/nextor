<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales',function(Blueprint $table) {
            $table->foreignId('source_quote_id')->nullable()
                ->constrained('sales')->nullOnDelete();
            $table->unique('source_quote_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales',function(Blueprint $table) {
            $table->dropForeign(['source_quote_id']);
            $table->dropUnique(['source_quote_id']);
            $table->dropColumn('source_quote_id');
        });
    }
};