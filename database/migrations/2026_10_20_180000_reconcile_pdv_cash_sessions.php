<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('pdv_cash_session_id')->nullable()
                ->after('source')->constrained('pdv_cash_sessions')->nullOnDelete();
            $table->index(['pdv_cash_session_id','completed_at'],'sales_cash_session_finished_idx');
        });

        Schema::table('pdv_cash_sessions', function (Blueprint $table) {
            $table->decimal('expected_amount',14,2)->nullable()->after('closing_amount');
            $table->decimal('difference_amount',14,2)->nullable()->after('expected_amount');
            $table->json('closing_breakdown')->nullable()->after('difference_amount');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('closed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
        });

        Schema::table('pdv_cash_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('user_id')
                ->constrained('sales')->nullOnDelete();
            $table->foreignId('sale_return_id')->nullable()->after('sale_id')
                ->constrained('sale_returns')->nullOnDelete();
            $table->index(['sale_id','type'],'pdv_movement_sale_type_idx');
            $table->index(['sale_return_id','type'],'pdv_movement_return_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_cash_movements', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->dropForeign(['sale_return_id']);
            $table->dropIndex('pdv_movement_sale_type_idx');
            $table->dropIndex('pdv_movement_return_type_idx');
            $table->dropColumn(['sale_id','sale_return_id']);
        });
        Schema::table('pdv_cash_sessions', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'expected_amount','difference_amount','closing_breakdown',
                'closed_by','reviewed_by','reviewed_at','review_notes',
            ]);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['pdv_cash_session_id']);
            $table->dropIndex('sales_cash_session_finished_idx');
            $table->dropColumn('pdv_cash_session_id');
        });
    }
};
