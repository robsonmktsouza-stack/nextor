<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_jobs', function (Blueprint $table): void {
            // Multiple fiscal attempts may refer to the same completed sale.
            // The sale is never created or accounted again on duplication.
            $table->dropUnique('fiscal_job_type_sale_unique');
            $table->index(['document_type', 'sale_id'], 'fiscal_job_type_sale_index');
            $table->foreignId('duplicated_from_id')
                ->nullable()
                ->unique('fiscal_job_duplicate_source_unique')
                ->constrained('fiscal_document_jobs')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_jobs', function (Blueprint $table): void {
            $table->dropForeign(['duplicated_from_id']);
            $table->dropUnique('fiscal_job_duplicate_source_unique');
            $table->dropColumn('duplicated_from_id');
            $table->dropIndex('fiscal_job_type_sale_index');
            // Historical duplicate records must be reconciled before rollback.
            $table->unique(['document_type', 'sale_id'], 'fiscal_job_type_sale_unique');
        });
    }
};
