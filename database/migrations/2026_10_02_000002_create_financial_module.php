<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name',120);
            $table->string('type',16)->index(); // income | expense
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['name','type']);
        });

        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name',120)->unique();
            $table->string('type',24)->default('cash'); // cash | bank | digital | other
            $table->decimal('opening_balance',14,2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type',16)->index(); // receivable | payable
            $table->string('status',16)->default('open')->index(); // open | partial | paid | cancelled

            $table->foreignId('category_id')->nullable()
                ->constrained('financial_categories')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()
                ->constrained('sales')->nullOnDelete();
            $table->foreignId('sale_payment_id')->nullable()->unique()
                ->constrained('sale_payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('description',190);
            $table->string('document_number',80)->nullable();
            $table->date('issue_date');
            $table->date('due_date')->index();
            $table->decimal('amount',14,2);
            $table->decimal('paid_amount',14,2)->default(0);
            $table->string('payment_method',40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['type','status','due_date'],'financial_entries_lookup_idx');
            $table->index(['customer_id','type'],'financial_entries_party_idx');
        });

        Schema::create('financial_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_entry_id')
                ->constrained('financial_entries')->cascadeOnDelete();
            $table->foreignId('financial_account_id')->nullable()
                ->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->decimal('amount',14,2);
            $table->date('settled_at')->index();
            $table->string('payment_method',40)->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('reversed_at')->nullable()->index();
            $table->string('reversal_reason',500)->nullable();
            $table->timestamps();

            $table->index(['financial_entry_id','reversed_at'],'financial_settlement_active_idx');
        });

        $now=now();

        DB::table('financial_categories')->insert([
            ['name'=>'Vendas','type'=>'income','is_active'=>true,'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'Outras receitas','type'=>'income','is_active'=>true,'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'Fornecedores','type'=>'expense','is_active'=>true,'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'Despesas gerais','type'=>'expense','is_active'=>true,'created_at'=>$now,'updated_at'=>$now],
        ]);

        $accountId=DB::table('financial_accounts')->insertGetId([
            'name'=>'Caixa principal',
            'type'=>'cash',
            'opening_balance'=>0,
            'is_active'=>true,
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);

        $salesCategoryId=DB::table('financial_categories')
            ->where('name','Vendas')
            ->where('type','income')
            ->value('id');

        // Backfill only sales that already have explicit payment records.
        // We intentionally do not guess the financial state of legacy sales without installments.
        if(Schema::hasTable('sale_payments')) {
            $payments=DB::table('sale_payments')
                ->join('sales','sales.id','=','sale_payments.sale_id')
                ->where('sales.operation_type','sale')
                ->where('sales.status','completed')
                ->select([
                    'sale_payments.id as payment_id',
                    'sale_payments.installment',
                    'sale_payments.amount',
                    'sale_payments.due_date',
                    'sale_payments.payment_method',
                    'sale_payments.receivable',
                    'sales.id as sale_id',
                    'sales.customer_id',
                    'sales.user_id',
                    'sales.operation_date',
                    'sales.completed_at',
                    'sales.created_at as sale_created_at',
                ])
                ->orderBy('sales.id')
                ->orderBy('sale_payments.installment')
                ->get();

            foreach($payments as $payment) {
                $issueDate=$payment->operation_date
                    ?: substr((string)($payment->completed_at ?: $payment->sale_created_at),0,10);
                $dueDate=$payment->due_date ?: $issueDate;
                $isOpen=(bool)$payment->receivable;

                $entryId=DB::table('financial_entries')->insertGetId([
                    'type'=>'receivable',
                    'status'=>$isOpen ? 'open' : 'paid',
                    'category_id'=>$salesCategoryId,
                    'customer_id'=>$payment->customer_id,
                    'sale_id'=>$payment->sale_id,
                    'sale_payment_id'=>$payment->payment_id,
                    'created_by'=>$payment->user_id,
                    'description'=>'Venda #'.str_pad((string)$payment->sale_id,5,'0',STR_PAD_LEFT)
                        .' - parcela '.(int)$payment->installment,
                    'document_number'=>'VENDA-'.$payment->sale_id,
                    'issue_date'=>$issueDate,
                    'due_date'=>$dueDate,
                    'amount'=>$payment->amount,
                    'paid_amount'=>$isOpen ? 0 : $payment->amount,
                    'payment_method'=>$payment->payment_method,
                    'notes'=>'Importado automaticamente do histórico de vendas.',
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]);

                if(!$isOpen) {
                    DB::table('financial_settlements')->insert([
                        'financial_entry_id'=>$entryId,
                        'financial_account_id'=>$accountId,
                        'user_id'=>$payment->user_id,
                        'amount'=>$payment->amount,
                        'settled_at'=>$issueDate,
                        'payment_method'=>$payment->payment_method,
                        'notes'=>'Recebimento importado da venda.',
                        'created_at'=>$now,
                        'updated_at'=>$now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_settlements');
        Schema::dropIfExists('financial_entries');
        Schema::dropIfExists('financial_accounts');
        Schema::dropIfExists('financial_categories');
    }
};
