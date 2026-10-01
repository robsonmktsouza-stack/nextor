<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use App\Services\SalesService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $status=$request->query('status','');
        $requestedPerPage=$request->integer('per_page');
        if(in_array($requestedPerPage,[10,25,50,100],true)) {
            $request->session()->put('table_per_page',$requestedPerPage);
        }
        $perPage=(int)$request->session()->get('table_per_page',25);
        if(!in_array($perPage,[10,25,50,100],true)) $perPage=25;
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

        $sales=Sale::with(['customer','user'])
            ->where(function($q) use ($period) {
                $q->where(function($dateQuery) use ($period) {
                    $dateQuery->whereNotNull('operation_date')
                        ->whereYear('operation_date',$period->year)
                        ->whereMonth('operation_date',$period->month);
                })->orWhere(function($legacyQuery) use ($period) {
                    $legacyQuery->whereNull('operation_date')
                        ->whereYear('created_at',$period->year)
                        ->whereMonth('created_at',$period->month);
                });
            })
            ->when(in_array($status,['completed','cancelled'],true),fn($q)=>$q->where('status',$status))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return view('sales.index',compact('sales','status','month','prevMonth','nextMonth','monthLabel'));
    }

    public function create()
    {
        return view('sales.create',[
            'products'=>Product::where('is_active',true)
                ->orderBy('name')
                ->get(['id','name','sku','unit','sale_price','stock_quantity','control_stock']),
            'services'=>Service::where('is_active',true)
                ->orderBy('name')
                ->get(['id','name','sale_price','service_list_item','cnae']),
            'customers'=>Customer::orderBy('name')->get(['id','name','document','final_consumer']),
        ]);
    }

    public function store(Request $request, SalesService $sales)
    {
        $data=$request->validate([
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'operation_type'=>['required','in:sale,quote'],
            'operation_date'=>['required','date'],
            'final_consumer'=>['nullable','boolean'],
            'keyword'=>['nullable','string','max:190'],
            'notes'=>['nullable','string','max:5000'],

            'items'=>['required','array','min:1','max:100'],
            'items.*.item_type'=>['required','in:product,service,freight,expense'],
            'items.*.product_id'=>['nullable','integer','exists:products,id'],
            'items.*.service_id'=>['nullable','integer','exists:services,id'],
            'items.*.description'=>['nullable','string','max:190'],
            'items.*.quantity'=>['required','numeric','gt:0','max:9999999999','decimal:0,3'],
            'items.*.unit_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'items.*.discount'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'items.*.notes'=>['nullable','string','max:1000'],

            'payments'=>['nullable','array','max:60'],
            'payments.*.amount'=>['required_with:payments','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'payments.*.due_date'=>['nullable','date'],
            'payments.*.payment_method'=>['nullable','string','max:40'],
            'payments.*.receivable'=>['nullable','boolean'],
        ]);

        $data['final_consumer']=$request->boolean('final_consumer',true);

        $sale=$sales->create($data,(int)$request->user()->id);

        $message=$sale->operation_type==='quote'
            ? 'Orçamento salvo.'
            : 'Venda concluída e estoque atualizado.';

        return redirect()->route('sales.show',$sale)->with('success',$message);
    }

    public function show(Sale $sale)
    {
        $sale->load(['items.product','items.service','payments','customer','user']);
        return view('sales.show',compact('sale'));
    }

    public function cancel(Request $request, Sale $sale, SalesService $sales)
    {
        $sales->cancel($sale,(int)$request->user()->id);

        $message=$sale->operation_type==='quote'
            ? 'Orçamento cancelado.'
            : 'Venda cancelada; quantidades devolvidas ao estoque.';

        return redirect()->route('sales.show',$sale)->with('success',$message);
    }
}
