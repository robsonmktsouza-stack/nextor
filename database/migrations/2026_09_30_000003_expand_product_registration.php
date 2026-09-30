<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('products', function (Blueprint $t) {
            $t->string('keywords',500)->nullable()->after('category');
            $t->string('usage_type',40)->default('resale')->after('keywords');
            $t->boolean('control_stock')->default(true)->after('usage_type');

            $t->string('origin',2)->default('0')->after('minimum_stock');
            $t->string('ean_gtin',32)->nullable()->after('origin');
            $t->decimal('net_weight',12,3)->nullable()->after('ean_gtin');
            $t->decimal('gross_weight',12,3)->nullable()->after('net_weight');
            $t->string('ncm',10)->nullable()->after('gross_weight');
            $t->string('ipi_exception',20)->nullable()->after('ncm');
            $t->string('cest',10)->nullable()->after('ipi_exception');
            $t->string('fiscal_benefit_code',40)->nullable()->after('cest');
            $t->boolean('different_tax_unit')->default(false)->after('fiscal_benefit_code');
            $t->string('tax_unit',12)->nullable()->after('different_tax_unit');
            $t->string('ignore_taxes_mode',30)->default('none')->after('tax_unit');
            $t->text('nfe_notes')->nullable()->after('ignore_taxes_mode');
            $t->string('tax_group',120)->nullable()->after('nfe_notes');

            $t->string('image_path')->nullable()->after('tax_group');
            $t->string('integration_reference',120)->nullable()->after('image_path');
            $t->string('integration_sku',120)->nullable()->after('integration_reference');
        });
    }

    public function down(): void {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn([
                'keywords','usage_type','control_stock','origin','ean_gtin','net_weight','gross_weight',
                'ncm','ipi_exception','cest','fiscal_benefit_code','different_tax_unit','tax_unit',
                'ignore_taxes_mode','nfe_notes','tax_group','image_path','integration_reference','integration_sku'
            ]);
        });
    }
};