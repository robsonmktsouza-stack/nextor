<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_jobs', function (Blueprint $table): void {
            $table->string('xml_path')->nullable();
            $table->string('response_path')->nullable();
        });
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
                $table->index(['queue', 'reserved_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('fiscal_document_jobs', function (Blueprint $table): void {
            $table->dropColumn(['xml_path', 'response_path']);
        });
        // Não apagar a tabela jobs: ela pode pertencer a outros módulos.
    }
};
