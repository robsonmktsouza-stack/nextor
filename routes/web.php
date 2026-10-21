<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\HistoryController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:5,1')->name('login.post');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/clients', [CompanyController::class, 'index'])->name('clients.index');
    Route::get('/clients/create', [CompanyController::class, 'create'])->name('clients.create');
    Route::post('/clients', [CompanyController::class, 'store'])->name('clients.store');
    Route::get('/clients/{company}', [CompanyController::class, 'show'])->name('clients.show');
    Route::get('/clients/{company}/details', [CompanyController::class, 'details'])->name('clients.details');
    Route::put('/clients/{company}', [CompanyController::class, 'update'])->name('clients.update');
    Route::post('/clients/{company}/note', [CompanyController::class, 'note'])->name('clients.note');
    Route::post('/clients/{company}/status', [LicenseController::class, 'status'])->name('clients.status');
    Route::post('/clients/{company}/renew', [LicenseController::class, 'renew'])->name('clients.renew');
    Route::post('/clients/{company}/modules', [LicenseController::class, 'modules'])->name('clients.modules');
    Route::get('/licenses', [LicenseController::class, 'index'])->name('licenses.index');
    Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
});
