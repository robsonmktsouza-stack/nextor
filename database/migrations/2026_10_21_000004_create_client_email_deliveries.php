<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->boolean('notify_client_email')->default(false);
        });
        Schema::create('client_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('fingerprint', 120);
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['automation_rule_id', 'company_id', 'fingerprint'], 'client_email_once_per_cycle');
            $table->index(['status', 'next_attempt_at'], 'client_email_queue_index');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('client_email_deliveries');
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropColumn('notify_client_email');
        });
    }
};