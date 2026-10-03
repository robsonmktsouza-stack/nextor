<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('operation_natures', function (Blueprint $table) {
            $table->string('cfop_inbound_internal',10)->nullable()->after('cfop_interstate');
            $table->string('cfop_inbound_interstate',10)->nullable()->after('cfop_inbound_internal');
        });

        Schema::table('nfe_drafts', function (Blueprint $table) {
            $table->decimal('discount',14,2)->default(0)->after('different_delivery');
            $table->decimal('surcharge',14,2)->default(0)->after('discount');
            $table->string('payment_type',4)->nullable()->after('surcharge');
            $table->string('payment_condition',30)->nullable()->after('payment_type');
            $table->string('payment_other_description',120)->nullable()->after('payment_condition');
            $table->string('card_brand',4)->nullable()->after('payment_other_description');
            $table->string('card_acquirer_document',20)->nullable()->after('card_brand');
            $table->string('card_authorization_code',40)->nullable()->after('card_acquirer_document');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('city_ibge_code',10)->nullable()->after('city');
            $table->string('country_code',10)->nullable()->after('district');
            $table->string('country_name',80)->nullable()->after('country_code');
            $table->string('foreign_id',40)->nullable()->after('country_name');
        });

        Schema::table('customer_delivery_addresses', function (Blueprint $table) {
            $table->string('city_ibge_code',10)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('customer_delivery_addresses', function (Blueprint $table) {
            $table->dropColumn('city_ibge_code');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['city_ibge_code','country_code','country_name','foreign_id']);
        });

        Schema::table('nfe_drafts', function (Blueprint $table) {
            $table->dropColumn([
                'discount','surcharge','payment_type','payment_condition','payment_other_description',
                'card_brand','card_acquirer_document','card_authorization_code',
            ]);
        });

        Schema::table('operation_natures', function (Blueprint $table) {
            $table->dropColumn(['cfop_inbound_internal','cfop_inbound_interstate']);
        });
    }
};
