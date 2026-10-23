<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('incoming_nfes', function (Blueprint $table): void {
            $table->string('manifestation_protocol', 30)->nullable();
            $table->timestamp('manifested_at')->nullable();
        });
        Schema::create('incoming_nfe_manifestation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incoming_nfe_id')->constrained('incoming_nfes')->cascadeOnDelete();
            $table->char('event_code', 6);
            $table->string('status', 20)->default('pending');
            $table->string('reason', 255)->nullable();
            $table->char('sefaz_status', 3)->nullable();
            $table->string('protocol', 30)->nullable();
            $table->string('response_message', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['incoming_nfe_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_nfe_manifestation_attempts');
        Schema::table('incoming_nfes', function (Blueprint $table): void {
            $table->dropColumn(['manifestation_protocol', 'manifested_at']);
        });
    }
};
