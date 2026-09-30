<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('trade_name',190)->nullable()->after('name');
            $t->string('contact_name',190)->nullable()->after('document');
            $t->boolean('is_customer')->default(true)->after('contact_name');
            $t->boolean('is_supplier')->default(false)->after('is_customer');
            $t->boolean('is_carrier')->default(false)->after('is_supplier');

            $t->string('zip_code',10)->nullable()->after('phone');
            $t->string('state',2)->nullable()->after('zip_code');
            $t->string('city',120)->nullable()->after('state');
            $t->string('address',190)->nullable()->after('city');
            $t->string('address_number',30)->nullable()->after('address');
            $t->string('address_complement',120)->nullable()->after('address_number');
            $t->string('district',120)->nullable()->after('address_complement');

            $t->boolean('final_consumer')->default(false)->after('district');
            $t->string('ie_indicator',32)->nullable()->after('final_consumer');
            $t->string('state_registration',40)->nullable()->after('ie_indicator');
            $t->string('substitute_state_registration',40)->nullable()->after('state_registration');
            $t->string('municipal_registration',40)->nullable()->after('substitute_state_registration');
            $t->string('suframa',40)->nullable()->after('municipal_registration');
            $t->string('government_entity',40)->nullable()->after('suframa');
            $t->string('rntrc',40)->nullable()->after('government_entity');
            $t->string('carrier_type',40)->nullable()->after('rntrc');
            $t->string('driver_license',40)->nullable()->after('carrier_type');

            $t->date('birth_date')->nullable()->after('driver_license');
            $t->string('keywords',500)->nullable()->after('birth_date');
            $t->date('celebration_date')->nullable()->after('keywords');
            $t->string('celebration_note',190)->nullable()->after('celebration_date');
            $t->string('lgpd_legal_basis',80)->nullable()->after('celebration_note');
        });

        Schema::create('customer_delivery_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('name',190)->nullable();
            $t->string('document',20)->nullable();
            $t->string('state_registration',40)->nullable();
            $t->string('zip_code',10)->nullable();
            $t->string('state',2)->nullable();
            $t->string('city',120)->nullable();
            $t->string('address',190)->nullable();
            $t->string('address_number',30)->nullable();
            $t->string('address_complement',120)->nullable();
            $t->string('district',120)->nullable();
            $t->string('email')->nullable();
            $t->string('phone',25)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('customer_delivery_addresses');
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn([
                'trade_name','contact_name','is_customer','is_supplier','is_carrier',
                'zip_code','state','city','address','address_number','address_complement','district',
                'final_consumer','ie_indicator','state_registration','substitute_state_registration',
                'municipal_registration','suframa','government_entity','rntrc','carrier_type','driver_license',
                'birth_date','keywords','celebration_date','celebration_note','lgpd_legal_basis'
            ]);
        });
    }
};