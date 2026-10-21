<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->string('kind', 40)->nullable()->index();
        });
        foreach (['expiry_soon', 'expired', 'offline'] as $code) {
            DB::table('automation_rules')->where('code', $code)->update(['kind' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
