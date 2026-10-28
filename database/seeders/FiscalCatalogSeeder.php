<?php

namespace Database\Seeders;

use App\Services\Fiscal\FiscalCatalogService;
use App\Services\Fiscal\FiscalCatalogTemplateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Explicit, idempotent installation; never run implicitly against a customer. */
final class FiscalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $release = app(FiscalCatalogService::class)->installBundled();
            app(FiscalCatalogTemplateService::class)->install($release->version);
        });
    }
}
