<?php
namespace App\Http\Controllers;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
class StockController extends Controller {
    public function index(Request $request) {
        $productId=$request->integer('product_id');
        $month=(string)$request->query('month',now()->format('Y-m'));
        try {
            $period=Carbon::createFromFormat('Y-m',$month)->startOfMonth();
        } catch (\Throwable $e) {
            $period=now()->startOfMonth();
        }
        $month=$period->format('Y-m');
        $prevMonth=$period->copy()->subMonth()->format('Y-m');
        $nextMonth=$period->copy()->addMonth()->format('Y-m');
        $monthLabel=ucfirst($period->locale('pt_BR')->translatedFormat('F Y'));
        $movements=StockMovement::with(['product','user'])
            ->whereYear('created_at',$period->year)
            ->whereMonth('created_at',$period->month)
            ->when($productId,fn($q)=>$q->where('product_id',$productId))
            ->orderByDesc('id')->paginate(15)->withQueryString();
        $products=Product::orderBy('name')->get(['id','name','sku','stock_quantity','unit']);
        return view('stock.index',compact('movements','products','productId','month','prevMonth','nextMonth','monthLabel'));
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
