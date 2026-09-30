<?php
namespace App\Http\Controllers;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class StockController extends Controller {
    public function index(Request $request) {
        $productId=$request->integer('product_id');
        $movements=StockMovement::with(['product','user'])
            ->when($productId,fn($q)=>$q->where('product_id',$productId))
            ->orderByDesc('id')->paginate(15)->withQueryString();
        $products=Product::orderBy('name')->get(['id','name','sku','stock_quantity','unit']);
        return view('stock.index',compact('movements','products','productId'));
    }
    public function store(Request $request, InventoryService $inventory) {
        $data=$request->validate([
            'product_id'=>['required','integer','exists:products,id'],
            'type'=>['required',Rule::in(['entry','exit','adjustment'])],
            'quantity'=>['required','numeric','min:0','max:9999999999','decimal:0,3'],
            'reason'=>['required','string','max:255'],
        ]);
        $inventory->adjust((int)$data['product_id'],$data['type'],(string)$data['quantity'],$data['reason'],(int)$request->user()->id);
        return redirect()->route('stock.index')->with('success','Movimentação registrada no histórico.');
    }
}
