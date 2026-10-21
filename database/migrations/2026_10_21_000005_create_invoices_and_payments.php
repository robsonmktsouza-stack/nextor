<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 24)->nullable()->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('company_name', 220);
            $table->string('company_cnpj', 14);
            $table->string('plan_code', 30);
            $table->string('plan_name', 100);
            $table->string('description', 255);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issue_date');
            $table->date('due_date')->index();
            $table->decimal('amount', 12, 2);
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'period_start', 'period_end'], 'invoices_period_index');
        });

        Schema::create('invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('method', 24);
            $table->string('reference', 120)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['invoice_id', 'reversed_at'], 'invoice_payment_effective_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoices');
    }
};
