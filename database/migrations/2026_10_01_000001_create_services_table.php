<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name',190);
            $table->decimal('sale_price',14,2)->default(0);
            $table->string('keywords',500)->nullable();
            $table->text('notes')->nullable();

            $table->string('service_list_item',20)->nullable();
            $table->string('cnae',12)->nullable();
            $table->string('municipal_tax_code',40)->nullable();
            $table->string('national_tax_code',40)->nullable();
            $table->string('nbs',20)->nullable();
            $table->string('tax_group',120)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('cnae');
            $table->index('service_list_item');
        });
    }

    public function down(): void {
        Schema::dropIfExists('services');
    }
};
