<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
class DashboardController extends Controller {
    public function __invoke() {
        return view('dashboard', [
            'productsCount' => Product::where('is_active', true)->count(),
            'lowStock' => Product::where('is_active',true)->whereColumn('stock_quantity','<=','minimum_stock')->count(),
            'stockValue' => Product::selectRaw('COALESCE(SUM(stock_quantity * cost_price), 0) AS total')->value('total'),
            'salesCount' => Sale::where('status','completed')->count(),
            'salesTotal' => Sale::where('status','completed')->sum('total'),
            'customersCount' => Customer::count(),
            'recentSales' => Sale::with('customer')->latest()->take(6)->get(),
            'lowProducts' => Product::where('is_active',true)->whereColumn('stock_quantity','<=','minimum_stock')->orderBy('stock_quantity')->take(5)->get(),
        ]);
    }
}
