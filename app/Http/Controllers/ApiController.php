<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function products(Request $request)
    {
        $updatedAfter=$request->query('updated_after');

        return response()->json(Product::query()
            ->when($updatedAfter,fn($q)=>$q->where('updated_at','>=',$updatedAfter))
            ->orderBy('id')
            ->paginate(min(100,max(1,$request->integer('per_page',50))),[
                'id','sku','name','unit','sale_price','stock_quantity','minimum_stock',
                'control_stock','is_active','updated_at'
            ]));
    }

    public function customers(Request $request)
    {
        $updatedAfter=$request->query('updated_after');

        return response()->json(Customer::query()
            ->when($updatedAfter,fn($q)=>$q->where('updated_at','>=',$updatedAfter))
            ->orderBy('id')
            ->paginate(min(100,max(1,$request->integer('per_page',50))),[
                'id','name','document','email','phone','is_customer','is_supplier','updated_at'
            ]));
    }

    public function sales(Request $request)
    {
        $updatedAfter=$request->query('updated_after');

        return response()->json(Sale::query()
            ->with(['customer:id,name,document','items','payments'])
            ->when($updatedAfter,fn($q)=>$q->where('updated_at','>=',$updatedAfter))
            ->orderByDesc('id')
            ->paginate(min(100,max(1,$request->integer('per_page',50)))));
    }
}
