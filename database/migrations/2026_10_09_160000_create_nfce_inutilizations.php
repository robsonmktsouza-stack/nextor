<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfce_inutilizations',function(Blueprint $table) {
            $table->id();
            $table->string('environment',20);
            $table->string('issuer_document',14);
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('series');
            $table->unsignedInteger('first_number');
            $table->unsignedInteger('last_number');
            $table->string('reason',255);
            $table->string('status',24)->default('pending');
            $table->string('protocol',24)->nullable();
            $table->string('response_path')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['issuer_document','environment','year','series']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('nfce_inutilizations');
    }
};
