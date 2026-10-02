<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('financial_entries', function (Blueprint $table) {
            $table->string('cost_center',120)->nullable()->after('keywords')->index();
        });
    }

    public function down(): void
    {
        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropColumn('cost_center');
        });
    }
};
