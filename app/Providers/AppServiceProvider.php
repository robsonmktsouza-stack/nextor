<?php

namespace App\Providers;

use App\Fiscal\Contracts\FiscalEngineInterface;
use App\Fiscal\NextorFiscalEngine;
use App\Fiscal\Tax\Contracts\TaxEngineInterface;
use App\Fiscal\Tax\Resolver\SimpleNationalRetailTaxEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaxEngineInterface::class, SimpleNationalRetailTaxEngine::class);
        $this->app->bind(FiscalEngineInterface::class, NextorFiscalEngine::class);
    }

    public function boot(): void
    {
    }
}
