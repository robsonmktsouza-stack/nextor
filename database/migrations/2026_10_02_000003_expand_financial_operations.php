<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_recurrences', function (Blueprint $table) {
            $table->id();
            $table->string('type',16)->index();
            $table->foreignId('category_id')->nullable()->constrained('financial_categories')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description',190);
            $table->decimal('amount',14,2);
            $table->string('frequency',20);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->date('start_date');
            $table->date('next_date')->index();
            $table->date('end_date')->nullable();
            $table->string('payment_method',40)->nullable();
            $table->string('keywords',255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamps();
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('category_id')
                ->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('recurrence_id')->nullable()->after('sale_payment_id')
                ->constrained('financial_recurrences')->nullOnDelete();
            $table->date('recurrence_occurrence_date')->nullable()->after('recurrence_id');
            $table->date('competence_date')->nullable()->after('issue_date')->index();
            $table->date('credit_date')->nullable()->after('due_date');
            $table->string('keywords',255)->nullable()->after('payment_method');
            $table->string('attachment_path',500)->nullable()->after('notes');
            $table->string('attachment_name',255)->nullable()->after('attachment_path');
            $table->unique(['recurrence_id','recurrence_occurrence_date'],'financial_entry_recurrence_unique');
        });

        Schema::create('financial_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount',14,2);
            $table->date('transfer_date')->index();
            $table->string('description',190)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('financial_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issuer_mode',20)->default('company');
            $table->string('recipient_name',190);
            $table->string('recipient_document',30)->nullable();
            $table->decimal('amount',14,2);
            $table->date('receipt_date')->index();
            $table->string('reference',500);
            $table->unsignedTinyInteger('copies')->default(1);
            $table->string('attachment_path',500)->nullable();
            $table->string('attachment_name',255)->nullable();
            $table->timestamps();
        });

        Schema::create('financial_bank_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name',255);
            $table->string('format',10);
            $table->string('file_sha256',64)->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('transactions_count')->default(0);
            $table->string('status',20)->default('imported')->index();
            $table->timestamps();
            $table->unique(['financial_account_id','file_sha256'],'financial_import_account_hash_unique');
        });

        Schema::create('financial_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_bank_import_id')->constrained('financial_bank_imports')->cascadeOnDelete();
            $table->foreignId('financial_entry_id')->nullable()->constrained('financial_entries')->nullOnDelete();
            $table->foreignId('financial_settlement_id')->nullable()->constrained('financial_settlements')->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('external_id',120)->nullable();
            $table->date('transaction_date')->index();
            $table->decimal('amount',14,2);
            $table->string('description',500);
            $table->string('transaction_type',20);
            $table->timestamp('reconciled_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['financial_bank_import_id','sequence'],'financial_bank_tx_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_bank_transactions');
        Schema::dropIfExists('financial_bank_imports');
        Schema::dropIfExists('financial_receipts');
        Schema::dropIfExists('financial_transfers');

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropUnique('financial_entry_recurrence_unique');
            $table->dropConstrainedForeignId('financial_account_id');
            $table->dropConstrainedForeignId('recurrence_id');
            $table->dropColumn([
                'recurrence_occurrence_date','competence_date','credit_date','keywords',
                'attachment_path','attachment_name',
            ]);
        });

        Schema::dropIfExists('financial_recurrences');
    }
};
