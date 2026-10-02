<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use App\Services\InventoryService;
use App\Services\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PdvController extends Controller
{
    public function index()
    {
        return view('pdv.index',[
            'customers'=>Customer::query()
                ->where('is_customer',true)
                ->orderBy('name')
                ->get(['id','name','document']),
            'recentSales'=>Sale::query()
                ->with('customer')
                ->where('source','pdv')
                ->latest('id')
                ->limit(6)
                ->get(['id','customer_id','total','status','completed_at','created_at']),
            'paymentMethods'=>PaymentMethod::query()
                ->where('is_active',true)
                ->where('pdv_enabled',true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'pdvSettings'=>AppSetting::groupValues('pdv',[
                'default_payment_method'=>null,
                'require_customer'=>false,
                'allow_discount'=>true,
                'show_stock'=>true,
            ]),
        ]);
    }

    public function search(Request $request)
    {
        $term=trim((string)$request->query('q',''));
        $kind=(string)$request->query('kind','all');

        if(mb_strlen($term)<1) {
            return response()->json(['items'=>[]]);
        }

        $items=collect();

        if(in_array($kind,['all','product'],true)) {
            $products=Product::query()
                ->where('is_active',true)
                ->where(function($q) use ($term) {
                    $q->where('name','like',"%{$term}%")
                        ->orWhere('sku','like',"%{$term}%")
                        ->orWhere('ean_gtin','like',"%{$term}%");
                })
                ->orderByRaw(
                    'CASE WHEN sku = ? OR ean_gtin = ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END',
                    [$term,$term,$term.'%']
                )
                ->orderBy('name')
                ->limit(20)
                ->get();

            foreach($products as $product) {
                $items->push([
                    'type'=>'product',
                    'id'=>$product->id,
                    'name'=>$product->name,
                    'code'=>$product->sku,
                    'ean'=>$product->ean_gtin,
                    'price'=>(float)$product->sale_price,
                    'stock'=>(float)$product->stock_quantity,
                    'unit'=>$product->unit,
                    'control_stock'=>(bool)$product->control_stock,
                    'image'=>$product->image_path ? Storage::disk('public')->url($product->image_path) : null,
                ]);
            }
        }

        if(in_array($kind,['all','service'],true)) {
            $services=Service::query()
                ->where('is_active',true)
                ->where(function($q) use ($term) {
                    $q->where('name','like',"%{$term}%")
                        ->orWhere('service_list_item','like',"%{$term}%")
                        ->orWhere('cnae','like',"%{$term}%");
                })
                ->orderBy('name')
                ->limit(12)
                ->get();

            foreach($services as $service) {
                $items->push([
                    'type'=>'service',
                    'id'=>$service->id,
                    'name'=>$service->name,
                    'code'=>$service->service_list_item ?: 'SERV-'.$service->id,
                    'ean'=>null,
                    'price'=>(float)$service->sale_price,
                    'stock'=>null,
                    'unit'=>'UN',
                    'control_stock'=>false,
                    'image'=>null,
                ]);
            }
        }

        return response()->json([
            'items'=>$items->take(30)->values(),
        ]);
    }

    public function store(Request $request, SalesService $sales)
    {
        $data=$request->validate([
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'payment_method'=>['required',Rule::exists('payment_methods','code')->where(fn($q)=>$q->where('is_active',true)->where('pdv_enabled',true))],
            'cash_received'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'notes'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1','max:150'],
            'items.*.item_type'=>['required','in:product,service'],
            'items.*.product_id'=>['nullable','integer','exists:products,id'],
            'items.*.service_id'=>['nullable','integer','exists:services,id'],
            'items.*.quantity'=>['required','numeric','gt:0','max:9999999999','decimal:0,3'],
            'items.*.discount'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
        ]);

        $paymentMethod=PaymentMethod::query()
            ->where('code',$data['payment_method'])
            ->where('is_active',true)
            ->where('pdv_enabled',true)
            ->firstOrFail();

        $isCash=$paymentMethod->kind==='cash';
        $isReceivable=$paymentMethod->kind==='bank_slip' || $paymentMethod->settlement_days>0;

        $productIds=collect($data['items'])
            ->where('item_type','product')
            ->pluck('product_id')
            ->filter()
            ->map(fn($id)=>(int)$id)
            ->unique()
            ->all();

        $serviceIds=collect($data['items'])
            ->where('item_type','service')
            ->pluck('service_id')
            ->filter()
            ->map(fn($id)=>(int)$id)
            ->unique()
            ->all();

        $products=Product::query()
            ->whereIn('id',$productIds)
            ->get()
            ->keyBy('id');

        $services=Service::query()
            ->whereIn('id',$serviceIds)
            ->get()
            ->keyBy('id');

        $rows=[];
        $totalCents=0;

        foreach($data['items'] as $index=>$row) {
            $type=$row['item_type'];
            $quantity=(float)$row['quantity'];
            $discountCents=(int)round((float)($row['discount'] ?? 0)*100);

            if($type==='product') {
                $item=$products->get((int)($row['product_id'] ?? 0));
                if(!$item || !$item->is_active) {
                    throw ValidationException::withMessages(["items.$index.product_id"=>'Produto inválido ou inativo.']);
                }
                $price=(float)$item->sale_price;
                $productId=$item->id;
                $serviceId=null;
            } else {
                $item=$services->get((int)($row['service_id'] ?? 0));
                if(!$item || !$item->is_active) {
                    throw ValidationException::withMessages(["items.$index.service_id"=>'Serviço inválido ou inativo.']);
                }
                $price=(float)$item->sale_price;
                $productId=null;
                $serviceId=$item->id;
            }

            $grossCents=(int)round($price*100*InventoryService::toMills($quantity)/1000);
            if($discountCents>$grossCents) {
                throw ValidationException::withMessages(["items.$index.discount"=>'O desconto é maior que o valor do item.']);
            }

            $totalCents += $grossCents-$discountCents;

            $rows[]=[
                'item_type'=>$type,
                'product_id'=>$productId,
                'service_id'=>$serviceId,
                'description'=>null,
                'quantity'=>$row['quantity'],
                'unit_price'=>number_format($price,2,'.',''),
                'discount'=>number_format($discountCents/100,2,'.',''),
                'notes'=>null,
            ];
        }

        $total=$totalCents/100;
        $cashReceived=(float)($data['cash_received'] ?? 0);

        if($isCash && $cashReceived+0.0001<$total) {
            throw ValidationException::withMessages([
                'cash_received'=>'O valor recebido é menor que o total da venda.',
            ]);
        }

        $sale=$sales->create([
            'customer_id'=>$data['customer_id'] ?? null,
            'operation_type'=>'sale',
            'source'=>'pdv',
            'operation_date'=>now()->toDateString(),
            'final_consumer'=>true,
            'keyword'=>'PDV',
            'notes'=>$data['notes'] ?? null,
            'items'=>$rows,
            'payments'=>[[
                'amount'=>number_format($total,2,'.',''),
                'due_date'=>now()->copy()->addDays($paymentMethod->settlement_days)->toDateString(),
                'payment_method'=>$data['payment_method'],
                'receivable'=>$isReceivable,
            ]],
        ],(int)$request->user()->id);

        $change=$isCash
            ? max(0,$cashReceived-(float)$sale->total)
            : 0;

        return redirect()
            ->route('pdv.receipt',$sale)
            ->with('pdv_last_sale',$sale->id)
            ->with('pdv_cash_received',$isCash ? $cashReceived : $total)
            ->with('pdv_change',$change);
    }

    public function receipt(Sale $sale)
    {
        abort_unless($sale->source==='pdv',404);

        $sale->load([
            'customer:id,name,document',
            'user:id,name',
            'items.product:id,unit',
            'payments',
        ]);

        $paymentLabels=PaymentMethod::query()->orderBy('sort_order')->pluck('name','code')->all();

        $cashReceived=(float)session('pdv_cash_received',(float)$sale->total);
        $change=(float)session('pdv_change',0);

        session()->keep(['pdv_last_sale','pdv_change']);

        return view('pdv.receipt',[
            'sale'=>$sale,
            'paymentLabels'=>$paymentLabels,
            'cashReceived'=>$cashReceived,
            'change'=>$change,
        ]);
    }
}
