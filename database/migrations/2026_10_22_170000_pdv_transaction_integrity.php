<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sales',function(Blueprint $table) {
            $table->uuid('pdv_checkout_key')->nullable()->unique();
            $table->string('pdv_checkout_hash',64)->nullable();
        });
        Schema::create('pdv_action_approvals',function(Blueprint $table) {
            $table->id();
            $table->string('action',50);
            $table->foreignId('performed_by')->index();
            $table->foreignId('approved_by')->index();
            $table->foreignId('sale_id')->nullable()->index();
            $table->foreignId('fiscal_document_job_id')->nullable()->index();
            $table->text('reason');
            $table->timestamp('created_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('pdv_action_approvals');
        Schema::table('sales',fn(Blueprint $table)=>$table->dropColumn(['pdv_checkout_key','pdv_checkout_hash']));
    }
};
