<?php

use App\Http\Controllers\LicenseCheckController;
use Illuminate\Support\Facades\Route;

Route::post('/license/check', LicenseCheckController::class)
    ->middleware('throttle:120,1')
    ->name('api.license.check');
