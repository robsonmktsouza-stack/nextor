<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_jobs', function (Blueprint $table): void {
            $table->foreignId('corrected_from_id')
                ->nullable()->unique('nfce_corrected_from_unique')
                ->constrained('fiscal_document_jobs')->restrictOnDelete();
        });

        Schema::create('nfce_correction_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_document_id')->constrained('fiscal_document_jobs')->restrictOnDelete();
            $table->foreignId('corrected_document_id')->unique()->constrained('fiscal_document_jobs')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_code', 4);
            $table->string('original_access_key', 44);
            $table->string('original_xml_path');
            $table->string('original_response_path');
            $table->string('original_xml_sha256', 64);
            $table->string('original_response_sha256', 64);
            $table->json('previous_snapshot');
            $table->json('corrected_snapshot');
            $table->json('changed_fields');
            $table->timestamps();

            $table->index(['original_document_id', 'created_at'], 'nfce_correction_origin_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfce_correction_revisions');
        Schema::table('fiscal_document_jobs', function (Blueprint $table): void {
            $table->dropForeign(['corrected_from_id']);
            $table->dropUnique('nfce_corrected_from_unique');
            $table->dropColumn('corrected_from_id');
        });
    }
};
