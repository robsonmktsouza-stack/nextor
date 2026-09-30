<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('users', function(Blueprint $t){
            $t->id(); $t->string('name'); $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable(); $t->string('password');
            $t->rememberToken(); $t->timestamps();
        });
        Schema::create('password_reset_tokens',function(Blueprint $t){
            $t->string('email')->primary();$t->string('token');$t->timestamp('created_at')->nullable();
        });
        Schema::create('products', function(Blueprint $t){
            $t->id();$t->string('sku',80)->unique();$t->string('name',190);
            $t->text('description')->nullable();$t->string('category',100)->nullable();
            $t->string('unit',12)->default('UN');
            $t->decimal('cost_price',14,2)->default(0);$t->decimal('sale_price',14,2)->default(0);
            $t->decimal('stock_quantity',14,3)->default(0);$t->decimal('minimum_stock',14,3)->default(0);
            $t->boolean('is_active')->default(true);$t->timestamps();
        });
        Schema::create('customers', function(Blueprint $t){
            $t->id();$t->string('name',190);$t->string('document',20)->nullable()->index();
            $t->string('email')->nullable();$t->string('phone',25)->nullable();$t->text('notes')->nullable();$t->timestamps();
        });
        Schema::create('sales', function(Blueprint $t){
            $t->id();$t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status',16)->default('completed')->index();$t->decimal('total',14,2);
            $t->text('notes')->nullable();$t->timestamp('completed_at')->nullable();$t->timestamp('cancelled_at')->nullable();$t->timestamps();
        });
        Schema::create('sale_items', function(Blueprint $t){
            $t->id();$t->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('product_name');$t->string('product_sku',80);
            $t->decimal('quantity',14,3);$t->decimal('unit_price',14,2);$t->decimal('line_total',14,2);
        });
        Schema::create('stock_movements',function(Blueprint $t){
            $t->id();$t->foreignId('product_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type',24);$t->decimal('quantity_delta',14,3);
            $t->decimal('previous_quantity',14,3);$t->decimal('new_quantity',14,3);
            $t->string('reason',255);$t->timestamp('created_at')->useCurrent();
            $t->index(['product_id','created_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('stock_movements');Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');Schema::dropIfExists('customers');Schema::dropIfExists('products');
        Schema::dropIfExists('password_reset_tokens');Schema::dropIfExists('users');
    }
};
