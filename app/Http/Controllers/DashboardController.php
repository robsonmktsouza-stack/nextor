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

        $salesTrend = collect(range(5, 0))->map(function ($offset) use ($pdv) {
            $date = now()->startOfMonth()->subMonths($offset);
            return [
                'key' => $date->format('Y-m'),
                'label' => $date->translatedFormat('M/y'),
                'total' => 0.0,
            ];
        });
        if ($pdv) {
            $firstMonth = now()->startOfMonth()->subMonths(5);
            $monthlySales = Sale::where('status', 'completed')
                ->where('created_at', '>=', $firstMonth)
                ->get(['created_at', 'total'])
                ->groupBy(fn ($sale) => $sale->created_at->format('Y-m'))
                ->map(fn ($sales) => (float) $sales->sum('total'));
            $salesTrend = $salesTrend->map(function ($month) use ($monthlySales) {
                $month['total'] = (float) ($monthlySales[$month['key']] ?? 0);
                return $month;
            });
        }

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
            'salesTrend' => $salesTrend,
            'licensedProducts' => $products,
            'licensedPdv' => $pdv,
        ]);
    }
}
