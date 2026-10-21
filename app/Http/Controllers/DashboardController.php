<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Support\CommercialModuleAccess;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $context = request()->attributes->get('lumeron_commercial_license', []);
        $products = CommercialModuleAccess::visible($context, 'products');
        $pdv = CommercialModuleAccess::visible($context, 'pdv');

        return view('dashboard', [
            'productsCount' => $products ? Product::where('is_active', true)->count() : 0,
            'lowStock' => $products ? Product::where('is_active', true)
                ->whereColumn('stock_quantity', '<=', 'minimum_stock')->count() : 0,
            'stockValue' => $products ? Product::selectRaw('COALESCE(SUM(stock_quantity * cost_price), 0) AS total')->value('total') : 0,
            'salesCount' => $pdv ? Sale::where('status', 'completed')->count() : 0,
            'salesTotal' => $pdv ? Sale::where('status', 'completed')->sum('total') : 0,
            'customersCount' => $pdv ? Customer::count() : 0,
            'recentSales' => $pdv ? Sale::with('customer')->latest()->take(6)->get() : collect(),
            'lowProducts' => $products ? Product::where('is_active', true)
                ->whereColumn('stock_quantity', '<=', 'minimum_stock')
                ->orderBy('stock_quantity')->take(5)->get() : collect(),
            'licensedProducts' => $products,
            'licensedPdv' => $pdv,
        ]);
    }
}
