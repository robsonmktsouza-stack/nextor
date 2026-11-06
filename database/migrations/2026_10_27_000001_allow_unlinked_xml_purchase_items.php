<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
            $table->string('source_code', 80)->nullable();
            $table->string('source_description', 190)->nullable();
            $table->string('source_unit', 12)->nullable();
            $table->string('source_ncm', 10)->nullable();
            $table->string('source_cfop', 4)->nullable();
            $table->string('source_ean', 32)->nullable();
            $table->unsignedInteger('source_number')->nullable();
        });
    }

    public function down(): void
    {
        if (\App\Models\PurchaseItem::query()->whereNull('product_id')->exists()) {
            throw new \RuntimeException('Vincule todos os itens importados antes de reverter esta migração.');
        }
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn(['source_code','source_description','source_unit','source_ncm','source_cfop','source_ean','source_number']);
            $table->foreignId('product_id')->nullable(false)->change();
        });
    }
};
