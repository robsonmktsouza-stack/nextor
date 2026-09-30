<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
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
    Route::post('/products',[ProductController::class,'store'])->name('products.store');
    Route::put('/products/{product}',[ProductController::class,'update'])->name('products.update');
    Route::post('/products/bulk-duplicate',[ProductController::class,'bulkDuplicate'])->name('products.bulk-duplicate');
    Route::post('/products/bulk-status',[ProductController::class,'bulkStatus'])->name('products.bulk-status');
    Route::delete('/products/bulk-delete',[ProductController::class,'bulkDelete'])->name('products.bulk-delete');
    Route::get('/customers',[CustomerController::class,'index'])->name('customers.index');
    Route::get('/customers/create',[CustomerController::class,'create'])->name('customers.create');
    Route::post('/customers',[CustomerController::class,'store'])->name('customers.store');
    Route::get('/customers/{customer}/edit',[CustomerController::class,'edit'])->name('customers.edit');
    Route::put('/customers/{customer}',[CustomerController::class,'update'])->name('customers.update');
    Route::post('/customers/bulk-duplicate',[CustomerController::class,'bulkDuplicate'])->name('customers.bulk-duplicate');
    Route::delete('/customers/bulk-delete',[CustomerController::class,'bulkDelete'])->name('customers.bulk-delete');
    Route::get('/stock',[StockController::class,'index'])->name('stock.index');
    Route::post('/stock',[StockController::class,'store'])->name('stock.store');
    Route::get('/sales',[SaleController::class,'index'])->name('sales.index');
    Route::get('/sales/create',[SaleController::class,'create'])->name('sales.create');
    Route::post('/sales',[SaleController::class,'store'])->name('sales.store');
    Route::get('/sales/{sale}',[SaleController::class,'show'])->name('sales.show');
    Route::post('/sales/{sale}/cancel',[SaleController::class,'cancel'])->name('sales.cancel');
});
