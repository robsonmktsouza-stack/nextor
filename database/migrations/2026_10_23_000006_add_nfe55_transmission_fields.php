<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nfe_drafts',function(Blueprint $table){
            $table->string('access_key',44)->nullable()->unique();
            $table->string('protocol',20)->nullable();
            $table->unsignedInteger('numeric_code')->nullable();
            $table->string('xml_path',255)->nullable();
            $table->string('response_path',255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('transmission_attempted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->unique(['environment','series','document_number'],'nfe_unique_environment_series_number');
        });
    }
    public function down(): void
    {
        Schema::table('nfe_drafts',function(Blueprint $table){
            $table->dropUnique('nfe_unique_environment_series_number');
            $table->dropUnique(['access_key']);
            $table->dropColumn(['access_key','protocol','numeric_code','xml_path','response_path',
                'error_message','transmission_attempted_at','processed_at','authorized_at']);
        });
    }
};
