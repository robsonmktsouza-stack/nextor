<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 140);
            $t->string('email')->unique();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('companies', function (Blueprint $t): void {
            $t->id();
            $t->uuid('instance_id')->unique();
            $t->string('legal_name', 220);
            $t->string('trade_name', 220)->nullable();
            $t->string('cnpj', 14)->unique();
            $t->string('contact_name', 160)->nullable();
            $t->string('email', 255);
            $t->string('phone', 30)->nullable();
            $t->string('subdomain', 40)->unique();
            $t->string('installation_url', 500)->nullable();
            $t->string('admin_login', 100)->nullable();
            $t->string('plan', 30)->default('standard');
            $t->string('license_status', 20)->default('active')->index();
            $t->date('expires_at')->nullable()->index();
            $t->json('modules')->nullable();
            $t->text('license_secret'); // Eloquent encrypted cast; never plaintext DB
            $t->timestamp('last_seen_at')->nullable();
            $t->string('last_version', 50)->nullable();
            $t->timestamps();
        });
        Schema::create('license_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event_type', 35)->index();
            $t->text('description');
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('license_events');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('users');
    }
};
