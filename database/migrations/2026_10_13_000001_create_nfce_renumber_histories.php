<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfce_renumber_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_document_job_id')->constrained('fiscal_document_jobs')->restrictOnDelete();
            $table->unsignedInteger('old_series');
            $table->unsignedBigInteger('old_number');
            $table->string('old_access_key', 44);
            $table->string('old_signed_xml_path');
            $table->string('old_sefaz_response_path');
            $table->string('old_consult_response_path')->nullable();
            $table->text('old_error_message')->nullable();
            $table->unsignedBigInteger('new_number');
            $table->timestamps();

            $table->index(['fiscal_document_job_id', 'created_at'], 'nfce_renumber_history_job_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfce_renumber_histories');
    }
};
