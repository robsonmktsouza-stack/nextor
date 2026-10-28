<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_catalog_locks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('fiscal_catalog_locks')->insert(['id' => 1]);
        Schema::create('fiscal_catalog_releases', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 32)->unique();
            $table->unsignedSmallInteger('schema_version');
            $table->char('sha256', 64);
            $table->date('consulted_at');
            $table->json('sources');
            $table->json('coverage');
            $table->unsignedInteger('entry_count');
            $table->timestamp('installed_at');
            $table->timestamps();
        });
        Schema::create('fiscal_catalog_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_id')->constrained('fiscal_catalog_releases')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('code', 64);
            $table->text('description');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->char('uf', 2)->nullable();
            $table->string('regime', 32)->nullable();
            $table->string('status', 16)->default('reference');
            $table->json('source');
            $table->json('conditions');
            $table->json('parameters');
            $table->char('entry_key', 64);
            $table->timestamps();
            $table->unique(['release_id', 'entry_key'], 'fiscal_catalog_entry_identity');
            $table->index(['release_id', 'kind', 'code'], 'fiscal_catalog_code_lookup');
            $table->index(['kind', 'uf', 'valid_from', 'valid_until'], 'fiscal_catalog_effectivity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_catalog_entries');
        Schema::dropIfExists('fiscal_catalog_releases');
        Schema::dropIfExists('fiscal_catalog_locks');
    }
};
