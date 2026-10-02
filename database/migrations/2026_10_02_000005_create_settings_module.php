<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('document',20)->nullable();
            $table->string('legal_name',190)->nullable();
            $table->string('trade_name',190)->nullable();
            $table->string('state_registration',40)->nullable();
            $table->string('municipal_registration',40)->nullable();
            $table->string('cnae_main',12)->nullable();
            $table->string('phone',30)->nullable();
            $table->string('email')->nullable();
            $table->string('zip_code',10)->nullable();
            $table->string('state',2)->nullable();
            $table->string('city',120)->nullable();
            $table->string('city_ibge_code',12)->nullable();
            $table->string('address',190)->nullable();
            $table->string('address_number',30)->nullable();
            $table->string('address_complement',120)->nullable();
            $table->string('district',120)->nullable();

            $table->string('tax_regime',60)->nullable();
            $table->string('crt',4)->nullable();
            $table->decimal('simple_rate',7,4)->nullable();
            $table->string('main_activity',40)->nullable();

            $table->string('logo_path')->nullable();
            $table->text('print_header')->nullable();
            $table->text('print_footer')->nullable();
            $table->boolean('show_currency_prefix')->default(true);
            $table->string('timezone',60)->default('America/Sao_Paulo');

            $table->string('certificate_path')->nullable();
            $table->longText('certificate_password')->nullable();
            $table->dateTime('certificate_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group',60);
            $table->string('key',120);
            $table->longText('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();

            $table->unique(['group','key']);
            $table->index('group');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code',40)->unique();
            $table->string('name',120);
            $table->string('kind',30)->default('other');
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->decimal('fee_percent',7,4)->default(0);
            $table->decimal('fee_fixed',14,2)->default(0);
            $table->unsignedInteger('settlement_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('pdv_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('payment_methods')->insert([
            ['code'=>'cash','name'=>'Dinheiro','kind'=>'cash','sort_order'=>10,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'pix','name'=>'PIX','kind'=>'pix','sort_order'=>20,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'debit_card','name'=>'Cartão de débito','kind'=>'card','sort_order'=>30,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'credit_card','name'=>'Cartão de crédito','kind'=>'card','sort_order'=>40,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'bank_slip','name'=>'Boleto','kind'=>'bank_slip','sort_order'=>50,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'bank_transfer','name'=>'Transferência','kind'=>'transfer','sort_order'=>60,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'other','name'=>'Outro','kind'=>'other','sort_order'=>70,'created_at'=>now(),'updated_at'=>now()],
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role',30)->default('admin')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            $table->json('permissions')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role','is_active','permissions']);
        });

        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('company_settings');
    }
};
