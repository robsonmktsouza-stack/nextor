<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if(!Schema::hasColumn('sales','cash_received')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('cash_received',14,2)->nullable()->after('consumer_name');
            });
        }

        if(!Schema::hasColumn('sales','change_amount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('change_amount',14,2)->default(0)->after('cash_received');
            });
        }
    }

    public function down(): void
    {
        $drop=[];

        if(Schema::hasColumn('sales','cash_received')) $drop[]='cash_received';
        if(Schema::hasColumn('sales','change_amount')) $drop[]='change_amount';

        if($drop) {
            Schema::table('sales', function (Blueprint $table) use($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
