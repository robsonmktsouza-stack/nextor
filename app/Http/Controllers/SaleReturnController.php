<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\InventoryService;
use App\Services\SaleReturnService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        $requestedPerPage=$request->integer('per_page');
        if(in_array($requestedPerPage,[10,25,50,100],true)) {
            $request->session()->put('table_per_page',$requestedPerPage);
        }
        $perPage=(int)$request->session()->get('table_per_page',25);
        if(!in_array($perPage,[10,25,50,100],true)) $perPage=25;

        $month=(string)$request->query('month',now()->format('Y-m'));
        try {
            $period=Carbon::createFromFormat('Y-m',$month)->startOfMonth();
        } catch (\Throwable) {
            $period=now()->startOfMonth();
        }

        $status=(string)$request->query('status','');
        $term=trim((string)$request->query('search',''));

        $query=SaleReturn::query()
            ->with(['sale.customer','user'])
            ->withCount('items')
            ->whereYear('return_date',$period->year)
            ->whereMonth('return_date',$period->month)
            ->when(in_array($status,['completed','cancelled'],true),fn($q)=>$q->where('status',$status))
            ->when($term,fn($q)=>$q->where(function($search) use($term){
                $numeric=(int)preg_replace('/\D+/','',$term);
                if($numeric>0) {
                    $search->orWhere('id',$numeric)->orWhere('sale_id',$numeric);
                }
                $search->orWhere('notes','like',"%{$term}%")
                    ->orWhereHas('sale.customer',fn($customer)=>$customer->where('name','like',"%{$term}%"));
            }));

        $returns=$query->orderByDesc('return_date')->orderByDesc('id')->paginate($perPage)->withQueryString();

        return view('sales.returns.index',[
            'returns'=>$returns,
            'status'=>$status,
            'term'=>$term,
            'month'=>$period->format('Y-m'),
            'prevMonth'=>$period->copy()->subMonth()->format('Y-m'),
            'nextMonth'=>$period->copy()->addMonth()->format('Y-m'),
            'monthLabel'=>ucfirst($period->locale('pt_BR')->translatedFormat('F Y')),
        ]);
    }

    public function create(Request $request)
    {
        $raw=trim((string)$request->query('sale',''));
        $sale=null;
        $searchError=null;
        $remaining=[];

        if($raw!=='') {
            $saleId=(int)preg_replace('/\D+/','',$raw);

            if($saleId>0) {
                $sale=Sale::query()
                    ->with(['items.product','items.service','customer','user'])
                    ->whereKey($saleId)
                    ->where('operation_type','sale')
                    ->first();
            }

            if(!$sale) {
                $searchError='Venda não encontrada.';
            } elseif($sale->status!=='completed') {
                $searchError='Esta venda está cancelada e não pode receber devolução.';
                $sale=null;
            } else {
                $returned=SaleReturnItem::query()
                    ->join('sale_returns','sale_returns.id','=','sale_return_items.sale_return_id')
                    ->where('sale_returns.sale_id',$sale->id)
                    ->where('sale_returns.status','completed')
                    ->groupBy('sale_return_items.sale_item_id')
                    ->selectRaw('sale_return_items.sale_item_id, COALESCE(SUM(sale_return_items.quantity),0) returned_quantity')
                    ->pluck('returned_quantity','sale_item_id');

                foreach($sale->items as $item) {
                    $sold=InventoryService::toMills($item->quantity);
                    $done=InventoryService::toMills($returned->get($item->id,'0'));
                    $remaining[$item->id]=InventoryService::formatMills(max(0,$sold-$done));
                }
            }
        }

        return view('sales.returns.create',compact('sale','raw','searchError','remaining'));
    }

    public function store(Request $request, SaleReturnService $returns)
    {
        $data=$request->validate([
            'sale_id'=>['required','integer','exists:sales,id'],
            'return_date'=>['required','date'],
            'notes'=>['nullable','string','max:5000'],
            'items'=>['required','array','min:1','max:100'],
            'items.*.sale_item_id'=>['required','integer','distinct','exists:sale_items,id'],
            'items.*.quantity'=>['nullable','numeric','min:0','max:9999999999','decimal:0,3'],
            'items.*.reason'=>['nullable','string','max:255'],
        ]);

        $saleReturn=$returns->create($data,(int)$request->user()->id);

        return redirect()->route('sales.returns.show',$saleReturn)
            ->with('success','Devolução registrada e estoque atualizado.');
    }

    public function show(SaleReturn $saleReturn)
    {
        $saleReturn->load(['sale.customer','sale.user','items.product','items.service','user']);
        return view('sales.returns.show',compact('saleReturn'));
    }

    public function cancel(SaleReturn $saleReturn, Request $request, SaleReturnService $returns)
    {
        $returns->cancel($saleReturn,(int)$request->user()->id);

        return redirect()->route('sales.returns.show',$saleReturn)
            ->with('success','Devolução cancelada e estoque revertido.');
    }
}
