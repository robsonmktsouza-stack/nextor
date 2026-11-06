<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('operation_natures', function (Blueprint $table): void {
            $table->string('preset_key', 80)->nullable()->unique();
            $table->unsignedInteger('revision')->default(1);
        });
        foreach (['operation_natures', 'fiscal_tax_groups'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('catalog_version', 32)->nullable()->index();
                $table->string('catalog_status', 20)->nullable()->index();
                $table->char('catalog_fingerprint', 64)->nullable();
                $table->json('catalog_metadata')->nullable();
                $table->timestamp('fiscal_reviewed_at')->nullable();
                $table->foreignId('fiscal_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
        foreach (['fiscal_operation_nature_revisions' => ['operation_nature_id', 'operation_natures'],
            'fiscal_tax_group_revisions' => ['fiscal_tax_group_id', 'fiscal_tax_groups']] as $name => [$foreign, $parent]) {
            Schema::create($name, function (Blueprint $table) use ($foreign, $parent, $name): void {
                $table->id();
                $table->foreignId($foreign)->constrained($parent)->restrictOnDelete();
                $table->unsignedInteger('revision');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('snapshot');
                $table->timestamp('created_at');
                $table->unique([$foreign, 'revision'], $name.'_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_group_revisions');
        Schema::dropIfExists('fiscal_operation_nature_revisions');
        foreach (['operation_natures', 'fiscal_tax_groups'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['catalog_version']);
                $table->dropIndex(['catalog_status']);
                $table->dropConstrainedForeignId('fiscal_reviewed_by');
                $table->dropColumn(['catalog_version', 'catalog_status', 'catalog_fingerprint', 'catalog_metadata', 'fiscal_reviewed_at']);
            });
        }
        Schema::table('operation_natures', function (Blueprint $table): void {
            $table->dropUnique(['preset_key']);
            $table->dropColumn(['preset_key', 'revision']);
        });
    }
};
