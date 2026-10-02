<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinancialReconciliationController;
use App\Http\Controllers\FinancialReceiptController;
use App\Http\Controllers\FinancialRecurrenceController;
use App\Http\Controllers\FinancialTransferController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PdvController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class,'loginForm'])->name('login');
    Route::post('/login', [AuthController::class,'login'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class,'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/products',[ProductController::class,'index'])->name('products.index');
    Route::get('/products/create',[ProductController::class,'create'])->name('products.create');
    Route::post('/products',[ProductController::class,'store'])->name('products.store');
    Route::get('/products/{product}/edit',[ProductController::class,'edit'])->name('products.edit');
    Route::put('/products/{product}',[ProductController::class,'update'])->name('products.update');
    Route::post('/products/bulk-duplicate',[ProductController::class,'bulkDuplicate'])->name('products.bulk-duplicate');
    Route::post('/products/bulk-status',[ProductController::class,'bulkStatus'])->name('products.bulk-status');
    Route::delete('/products/bulk-delete',[ProductController::class,'bulkDelete'])->name('products.bulk-delete');
    Route::get('/services',[ServiceController::class,'index'])->name('services.index');
    Route::get('/services/create',[ServiceController::class,'create'])->name('services.create');
    Route::post('/services',[ServiceController::class,'store'])->name('services.store');
    Route::get('/services/{service}/edit',[ServiceController::class,'edit'])->name('services.edit');
    Route::put('/services/{service}',[ServiceController::class,'update'])->name('services.update');
    Route::post('/services/bulk-duplicate',[ServiceController::class,'bulkDuplicate'])->name('services.bulk-duplicate');
    Route::post('/services/bulk-status',[ServiceController::class,'bulkStatus'])->name('services.bulk-status');
    Route::delete('/services/bulk-delete',[ServiceController::class,'bulkDelete'])->name('services.bulk-delete');
    Route::get('/customers',[CustomerController::class,'index'])->name('customers.index');
    Route::get('/customers/cnpj/{cnpj}',[CustomerController::class,'lookupCnpj'])->where('cnpj','[0-9A-Za-z.\\/-]+')->name('customers.lookup-cnpj');
    Route::get('/customers/create',[CustomerController::class,'create'])->name('customers.create');
    Route::post('/customers',[CustomerController::class,'store'])->name('customers.store');
    Route::get('/customers/{customer}/edit',[CustomerController::class,'edit'])->name('customers.edit');
    Route::put('/customers/{customer}',[CustomerController::class,'update'])->name('customers.update');
    Route::post('/customers/bulk-duplicate',[CustomerController::class,'bulkDuplicate'])->name('customers.bulk-duplicate');
    Route::delete('/customers/bulk-delete',[CustomerController::class,'bulkDelete'])->name('customers.bulk-delete');
    Route::get('/stock',[StockController::class,'index'])->name('stock.index');
    Route::post('/stock',[StockController::class,'store'])->name('stock.store');
    Route::get('/pdv',[PdvController::class,'index'])->name('pdv.index');
    Route::get('/pdv/search',[PdvController::class,'search'])->name('pdv.search');
    Route::get('/pdv/receipt/{sale}',[PdvController::class,'receipt'])->name('pdv.receipt');
    Route::post('/pdv',[PdvController::class,'store'])->name('pdv.store');
    Route::get('/sales',[SaleController::class,'index'])->name('sales.index');
    Route::get('/sales/create',[SaleController::class,'create'])->name('sales.create');
    Route::post('/sales',[SaleController::class,'store'])->name('sales.store');
    Route::get('/sales/{sale}',[SaleController::class,'show'])->name('sales.show');
    Route::post('/sales/{sale}/cancel',[SaleController::class,'cancel'])->name('sales.cancel');

    Route::get('/finance',[FinanceController::class,'dashboard'])->name('finance.dashboard');
    Route::get('/finance/entries',[FinanceController::class,'entries'])->name('finance.entries');
    Route::get('/finance/entries/create',[FinanceController::class,'create'])->name('finance.entries.create');
    Route::post('/finance/entries',[FinanceController::class,'store'])->name('finance.entries.store');
    Route::post('/finance/entries/bulk-action',[FinanceController::class,'bulkAction'])->name('finance.entries.bulk-action');
    Route::get('/finance/entries/{entry}',[FinanceController::class,'show'])->name('finance.entries.show');
    Route::get('/finance/entries/{entry}/edit',[FinanceController::class,'edit'])->name('finance.entries.edit');
    Route::put('/finance/entries/{entry}',[FinanceController::class,'update'])->name('finance.entries.update');
    Route::post('/finance/entries/{entry}/settle',[FinanceController::class,'settle'])->name('finance.entries.settle');
    Route::post('/finance/entries/{entry}/cancel',[FinanceController::class,'cancel'])->name('finance.entries.cancel');
    Route::post('/finance/settlements/{settlement}/reverse',[FinanceController::class,'reverseSettlement'])->name('finance.settlements.reverse');

    Route::get('/finance/transfers',[FinancialTransferController::class,'index'])->name('finance.transfers.index');
    Route::get('/finance/transfers/create',[FinancialTransferController::class,'create'])->name('finance.transfers.create');
    Route::post('/finance/transfers',[FinancialTransferController::class,'store'])->name('finance.transfers.store');
    Route::post('/finance/transfers/{transfer}/cancel',[FinancialTransferController::class,'cancel'])->name('finance.transfers.cancel');

    Route::get('/finance/recurrences',[FinancialRecurrenceController::class,'index'])->name('finance.recurrences.index');
    Route::get('/finance/recurrences/create',[FinancialRecurrenceController::class,'create'])->name('finance.recurrences.create');
    Route::post('/finance/recurrences',[FinancialRecurrenceController::class,'store'])->name('finance.recurrences.store');
    Route::post('/finance/recurrences/generate',[FinancialRecurrenceController::class,'generate'])->name('finance.recurrences.generate');
    Route::post('/finance/recurrences/{recurrence}/toggle',[FinancialRecurrenceController::class,'toggle'])->name('finance.recurrences.toggle');

    Route::get('/finance/receipts',[FinancialReceiptController::class,'index'])->name('finance.receipts.index');
    Route::get('/finance/receipts/create',[FinancialReceiptController::class,'create'])->name('finance.receipts.create');
    Route::post('/finance/receipts',[FinancialReceiptController::class,'store'])->name('finance.receipts.store');
    Route::get('/finance/receipts/{receipt}/print',[FinancialReceiptController::class,'printReceipt'])->name('finance.receipts.print');
    Route::get('/finance/receipts/{receipt}/attachment',[FinancialReceiptController::class,'attachment'])->name('finance.receipts.attachment');

    Route::get('/finance/reconciliation',[FinancialReconciliationController::class,'index'])->name('finance.reconciliation.index');
    Route::get('/finance/reconciliation/import',[FinancialReconciliationController::class,'createImport'])->name('finance.reconciliation.import');
    Route::post('/finance/reconciliation/import',[FinancialReconciliationController::class,'storeImport'])->name('finance.reconciliation.store-import');
    Route::post('/finance/reconciliation/transactions/{transaction}/match',[FinancialReconciliationController::class,'match'])->name('finance.reconciliation.match');
    Route::post('/finance/reconciliation/transactions/{transaction}/unmatch',[FinancialReconciliationController::class,'unmatch'])->name('finance.reconciliation.unmatch');

    Route::get('/finance/entries/{entry}/attachment',[FinanceController::class,'attachment'])->name('finance.entries.attachment');

    Route::get('/finance/settings',[FinanceController::class,'settings'])->name('finance.settings');
    Route::post('/finance/categories',[FinanceController::class,'storeCategory'])->name('finance.categories.store');
    Route::post('/finance/categories/{category}/toggle',[FinanceController::class,'toggleCategory'])->name('finance.categories.toggle');
    Route::post('/finance/accounts',[FinanceController::class,'storeAccount'])->name('finance.accounts.store');
    Route::post('/finance/accounts/{account}/toggle',[FinanceController::class,'toggleAccount'])->name('finance.accounts.toggle');
});
