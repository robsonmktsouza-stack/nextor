<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdv_cash_registers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('pdv_pin_hash', 255)->nullable();
        });
        Schema::table('pdv_cash_sessions', function (Blueprint $table) {
            $table->foreignId('register_id')->nullable()->index();
            $table->foreignId('previous_session_id')->nullable()->index();
            $table->foreignId('handover_to')->nullable()->index();
        });
        // Never attach a legacy open session to an arbitrary physical register:
        // older sessions remain readable and can be closed by their owner.
        DB::table('pdv_cash_registers')->insert([
            'code' => 'CX01',
            'name' => 'Caixa 01',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('pdv_cash_sessions', function (Blueprint $table) {
            $table->dropColumn(['register_id', 'previous_session_id', 'handover_to']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pdv_pin_hash');
        });
        Schema::dropIfExists('pdv_cash_registers');
    }
};
