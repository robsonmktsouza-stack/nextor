<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfe_drafts',function(Blueprint $table){
            $table->string('cancellation_status',20)->nullable()->index();
            $table->string('cancellation_reason',255)->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->timestamp('cancellation_attempted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_protocol',20)->nullable();
            $table->string('cancellation_response_path',255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfe_drafts',function(Blueprint $table){
            $table->dropIndex(['cancellation_status']);
            $table->dropColumn([
                'cancellation_status','cancellation_reason','cancellation_requested_at',
                'cancellation_attempted_at','cancelled_at','cancellation_protocol',
                'cancellation_response_path',
            ]);
        });
    }
};
