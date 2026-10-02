<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('consumer_document',20)->nullable()->after('customer_id')->index();
            $table->string('consumer_name',190)->nullable()->after('consumer_document');
            $table->decimal('cash_received',14,2)->nullable()->after('consumer_name');
            $table->decimal('change_amount',14,2)->default(0)->after('cash_received');
        });

        Schema::create('pdv_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('pdv_cash_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type',20)->index();
            $table->decimal('amount',14,2);
            $table->string('reason',255);
            $table->timestamps();

            $table->index(['cash_session_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_cash_movements');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['consumer_document','consumer_name','cash_received','change_amount']);
        });
    }
};
