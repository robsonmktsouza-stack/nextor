<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('control_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('smtp_host', 253)->nullable();
            $table->unsignedInteger('smtp_port')->default(587);
            $table->string('smtp_security', 12)->default('tls');
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('smtp_from_address', 255)->nullable();
            $table->string('smtp_from_name', 100)->default('Lumeron');
            $table->boolean('email_enabled')->default(false);
            $table->boolean('invoice_email_enabled')->default(false);
            $table->boolean('license_email_enabled')->default(false);
            $table->boolean('recurring_invoices_enabled')->default(true);
            $table->unsignedTinyInteger('invoice_days_ahead')->default(7);
            $table->string('automations_at', 5)->default('08:00');
            $table->string('invoices_at', 5)->default('08:05');
            $table->string('emails_start_at', 5)->default('08:10');
            $table->string('emails_end_at', 5)->default('18:00');
            $table->timestamps();
        });
        Schema::create('invoice_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_email_deliveries');
        Schema::dropIfExists('control_settings');
    }
};
