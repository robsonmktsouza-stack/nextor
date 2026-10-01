<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('operation_type',20)->default('sale')->after('user_id')->index();
            $table->date('operation_date')->nullable()->after('operation_type');
            $table->boolean('final_consumer')->default(true)->after('operation_date');
            $table->string('keyword',190)->nullable()->after('final_consumer');
            $table->decimal('subtotal',14,2)->default(0)->after('status');
            $table->decimal('discount_total',14,2)->default(0)->after('subtotal');
        });

        DB::table('sales')->update([
            'subtotal'=>DB::raw('total'),
        ]);

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('item_type',20)->default('product')->after('sale_id')->index();
            $table->foreignId('service_id')->nullable()->after('product_id')->constrained('services')->nullOnDelete();
            $table->decimal('discount',14,2)->default(0)->after('unit_price');
            $table->text('notes')->nullable()->after('line_total');
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installment')->default(1);
            $table->decimal('amount',14,2);
            $table->date('due_date')->nullable();
            $table->string('payment_method',40)->nullable();
            $table->boolean('receivable')->default(true);
            $table->timestamps();

            $table->index(['sale_id','installment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['item_type','discount','notes']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'operation_type','operation_date','final_consumer','keyword',
                'subtotal','discount_total'
            ]);
        });
    }
};
