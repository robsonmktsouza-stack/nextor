<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchases',function(Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained('customers')->restrictOnDelete();
            $t->foreignId('incoming_nfe_id')->nullable()->unique()->constrained('incoming_nfes')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status',20)->default('draft')->index();
            $t->date('operation_date')->index();
            $t->date('due_date')->nullable();
            $t->string('document_number',80)->nullable();
            $t->decimal('subtotal',14,2)->default(0);
            $t->decimal('freight',14,2)->default(0);
            $t->decimal('discount',14,2)->default(0);
            $t->decimal('total',14,2)->default(0);
            $t->boolean('generate_payable')->default(true);
            $t->text('notes')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['status','operation_date']);
        });
        Schema::create('purchase_items',function(Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->string('product_name',190);
            $t->string('product_sku',80);
            $t->string('unit',12);
            $t->decimal('quantity',14,3);
            $t->decimal('unit_cost',14,2);
            $t->decimal('line_total',14,2);
            $t->unique(['purchase_id','product_id']);
        });
        Schema::table('stock_movements',function(Blueprint $t) {
            $t->foreignId('purchase_id')->nullable()->constrained('purchases')->restrictOnDelete();
        });
        Schema::table('financial_entries',function(Blueprint $t) {
            $t->foreignId('purchase_id')->nullable()->unique()->constrained('purchases')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('financial_entries',fn(Blueprint $t)=>$t->dropConstrainedForeignId('purchase_id'));
        Schema::table('stock_movements',fn(Blueprint $t)=>$t->dropConstrainedForeignId('purchase_id'));
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
