<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('central_license_states', function (Blueprint $table): void {
            $table->unsignedSmallInteger('notice_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('central_license_states', function (Blueprint $table): void {
            $table->dropColumn('notice_days');
        });
    }
};
