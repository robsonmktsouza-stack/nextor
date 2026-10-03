<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operation_natures', function (Blueprint $table) {
            $table->id();
            $table->string('name',160);
            $table->string('operation_type',20)->default('outbound');
            $table->string('purpose',30)->default('normal');
            $table->string('cfop_internal',10)->nullable();
            $table->string('cfop_interstate',10)->nullable();
            $table->string('cfop_foreign',10)->nullable();
            $table->boolean('override_product_cfop')->default(true);
            $table->boolean('final_consumer_default')->default(false);
            $table->string('presence_default',30)->default('not_applicable');
            $table->boolean('move_stock')->default(true);
            $table->boolean('generate_finance')->default(true);
            $table->boolean('allow_referenced_document')->default(true);
            $table->boolean('require_transport')->default(false);
            $table->boolean('require_invoice')->default(false);
            $table->boolean('require_duplicates')->default(false);
            $table->text('additional_info')->nullable();
            $table->text('tax_authority_info')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active','name']);
        });

        Schema::create('nfe_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_nature_id')->nullable()->constrained('operation_natures')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status',30)->default('draft');
            $table->string('operation_type',20)->default('outbound');
            $table->string('destination',20)->default('auto');
            $table->string('presence',30)->default('not_applicable');
            $table->string('purpose',30)->default('normal');
            $table->boolean('final_consumer')->default(false);

            $table->date('issue_date');
            $table->time('issue_time')->nullable();
            $table->date('exit_date')->nullable();
            $table->time('exit_time')->nullable();
            $table->date('expected_delivery_date')->nullable();

            $table->boolean('government_purchase')->default(false);
            $table->boolean('advance_payment')->default(false);
            $table->boolean('different_delivery')->default(false);

            $table->unsignedInteger('series')->nullable();
            $table->unsignedBigInteger('document_number')->nullable();
            $table->string('environment',20)->default('homologation');

            $table->json('emitter_snapshot')->nullable();
            $table->json('recipient_snapshot')->nullable();
            $table->json('delivery_snapshot')->nullable();
            $table->json('transport_data')->nullable();
            $table->json('invoice_data')->nullable();
            $table->json('duplicates')->nullable();
            $table->json('payments')->nullable();
            $table->json('references')->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('totals')->nullable();

            $table->text('additional_info')->nullable();
            $table->text('tax_authority_info')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['status','issue_date']);
        });

        Schema::create('nfe_draft_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nfe_draft_id')->constrained('nfe_drafts')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('item_number');

            $table->string('product_name',190);
            $table->string('product_sku',80)->nullable();
            $table->decimal('quantity',14,4)->default(1);
            $table->decimal('unit_price',14,4)->default(0);
            $table->decimal('freight',14,2)->default(0);
            $table->decimal('insurance',14,2)->default(0);
            $table->decimal('other_expenses',14,2)->default(0);
            $table->decimal('discount',14,2)->default(0);
            $table->decimal('line_total',14,2)->default(0);

            $table->string('cfop',10)->nullable();
            $table->string('origin',2)->nullable();
            $table->string('ean_gtin',32)->nullable();
            $table->string('unit',12)->nullable();
            $table->string('tax_unit',12)->nullable();
            $table->string('ncm',10)->nullable();
            $table->string('cest',10)->nullable();
            $table->string('ipi_exception',20)->nullable();
            $table->string('fiscal_benefit_code',40)->nullable();
            $table->string('purchase_order',60)->nullable();
            $table->string('purchase_order_item',20)->nullable();
            $table->text('notes')->nullable();

            $table->json('tax_data')->nullable();
            $table->json('special_data')->nullable();
            $table->timestamps();

            $table->unique(['nfe_draft_id','item_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfe_draft_items');
        Schema::dropIfExists('nfe_drafts');
        Schema::dropIfExists('operation_natures');
    }
};
