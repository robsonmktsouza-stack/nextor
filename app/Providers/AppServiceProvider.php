<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::defaultView('pagination::simple-bootstrap-4');
    }
}
