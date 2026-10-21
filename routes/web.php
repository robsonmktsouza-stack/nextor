<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\InvoiceController;
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
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/create', [PlanController::class, 'create'])->name('plans.create');
    Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::get('/automations', [AutomationController::class, 'index'])->name('automations.index');
    Route::get('/automations/create', [AutomationController::class, 'create'])->name('automations.create');
    Route::post('/automations', [AutomationController::class, 'store'])->name('automations.store');
    Route::get('/automations/{rule}/edit', [AutomationController::class, 'edit'])->name('automations.edit');
    Route::put('/automations/{rule}', [AutomationController::class, 'update'])->name('automations.update');
    Route::post('/automations/run', [AutomationController::class, 'run'])->middleware('throttle:3,1')->name('automations.run');
    Route::post('/automations/send-emails', [AutomationController::class, 'sendEmails'])->middleware('throttle:3,1')->name('automations.send-emails');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->name('invoices.pay');
    Route::post('/invoices/{invoice}/payments/{payment}/reverse', [InvoiceController::class, 'reverse'])->name('invoices.payments.reverse');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('/invoices/{invoice}/apply-license', [InvoiceController::class, 'applyLicense'])->name('invoices.apply-license');
    Route::post('/invoices/{invoice}/undo-license', [InvoiceController::class, 'undoLicense'])->name('invoices.undo-license');
    Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
});
