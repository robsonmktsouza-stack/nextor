<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\SalesService;
use Illuminate\Http\Request;
class SaleController extends Controller {
    public function index(Request $request) {
        $status=$request->query('status','');
        $sales=Sale::with(['customer','user'])->when(in_array($status,['completed','cancelled'],true),fn($q)=>$q->where('status',$status))
            ->latest()->paginate(15)->withQueryString();
        return view('sales.index',compact('sales','status'));
    }
    public function create() {
        return view('sales.create',[
            'products'=>Product::where('is_active',true)->orderBy('name')->get(['id','name','sku','unit','sale_price','stock_quantity']),
            'customers'=>Customer::orderBy('name')->get(['id','name','document']),
        ]);
    }
    public function store(Request $request, SalesService $sales) {
        $data=$request->validate([
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'notes'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1','max:100'],
            'items.*.product_id'=>['required','integer','distinct','exists:products,id'],
            'items.*.quantity'=>['required','numeric','gt:0','max:9999999999','decimal:0,3'],
        ]);
        $sale=$sales->create($data,(int)$request->user()->id);
        return redirect()->route('sales.show',$sale)->with('success','Venda concluída e estoque baixado.');
    }
    public function show(Sale $sale) {
        $sale->load(['items.product','customer','user']);
        return view('sales.show',compact('sale'));
    }
    public function cancel(Request $request, Sale $sale, SalesService $sales) {
        $sales->cancel($sale,(int)$request->user()->id);
        return redirect()->route('sales.show',$sale)->with('success','Venda cancelada; quantidades devolvidas ao estoque.');
    }
}
