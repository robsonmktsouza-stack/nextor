<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('license_applied_at')->nullable();
            $table->timestamp('license_application_reversed_at')->nullable();
            $table->date('previous_license_expiry')->nullable();
            $table->foreignId('license_applied_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('license_applied_by');
            $table->dropColumn(['license_applied_at', 'license_application_reversed_at', 'previous_license_expiry']);
        });
    }
};
