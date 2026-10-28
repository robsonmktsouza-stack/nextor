<?php

use App\Services\Fiscal\FiscalCatalogService;
use App\Services\Fiscal\FiscalCatalogTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provision the bundled, reviewed fiscal reference catalog for every tenant DB.
 * Idempotent services preserve existing releases and customer customization.
 * New bundles must be accompanied by a new versioned migration or deployment step.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $release=app(FiscalCatalogService::class)->installBundled();
            app(FiscalCatalogTemplateService::class)->install($release->version);
        },3);
    }

    public function down(): void
    {
        // Reference data and user-adjusted templates must never be deleted on rollback.
    }
};
