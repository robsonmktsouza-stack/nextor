<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PdvCashMovement;
use App\Models\PdvCashSession;
use App\Models\SalePayment;
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
    public function index(Request $request)
    {
        $pdvSettings=AppSetting::groupValues('pdv',[
            'default_payment_method'=>null,
            'require_customer'=>false,
            'allow_discount'=>true,
            'require_cash_opening'=>false,
            'allow_split_payment'=>true,
            'ask_consumer_document'=>true,
            'allow_split_payment'=>true,
            'allow_cash_movements'=>true,
            'show_stock'=>true,
            'receipt_width'=>'80',
            'receipt_copies'=>1,
        ]);

        $cashSession=PdvCashSession::query()
            ->where('user_id',$request->user()->id)
            ->open()
            ->latest('opened_at')
            ->first();

        $cashExpected=$cashSession ? $this->cashExpectedForSession($cashSession) : null;
        $cashMovements=$cashSession
            ? PdvCashMovement::query()
                ->where('cash_session_id',$cashSession->id)
                ->latest('id')
                ->limit(8)
                ->get()
            : collect();

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
            'pdvSettings'=>$pdvSettings,
            'cashSession'=>$cashSession,
            'cashExpected'=>$cashExpected,
            'cashMovements'=>$cashMovements,
            'allowNegativeStock'=>(bool)AppSetting::value(
                'inventory',
                'allow_negative_stock',
                AppSetting::value('operations','allow_negative_stock',false)
            ),
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
            'consumer_document'=>['nullable','string','max:20'],
            'consumer_name'=>['nullable','string','max:190'],
            'payment_method'=>['nullable',Rule::exists('payment_methods','code')->where(fn($q)=>$q->where('is_active',true)->where('pdv_enabled',true))],
            'payments'=>['nullable','array','max:10'],
            'payments.*.payment_method'=>['required_with:payments','string',Rule::exists('payment_methods','code')->where(fn($q)=>$q->where('is_active',true)->where('pdv_enabled',true))],
            'payments.*.amount'=>['required_with:payments','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'cash_received'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'notes'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1','max:150'],
            'items.*.item_type'=>['required','in:product,service'],
            'items.*.product_id'=>['nullable','integer','exists:products,id'],
            'items.*.service_id'=>['nullable','integer','exists:services,id'],
            'items.*.quantity'=>['required','numeric','gt:0','max:9999999999','decimal:0,3'],
            'items.*.discount'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
        ]);

        $pdvSettings=AppSetting::groupValues('pdv',[
            'require_customer'=>false,
            'allow_discount'=>true,
            'require_cash_opening'=>false,
        ]);

        if((bool)$pdvSettings['require_cash_opening']) {
            $hasOpenCash=PdvCashSession::query()
                ->where('user_id',$request->user()->id)
                ->open()
                ->exists();

            if(!$hasOpenCash) {
                throw ValidationException::withMessages([
                    'cash_session'=>'Abra o caixa antes de finalizar uma venda no PDV.',
                ]);
            }
        }

        if((bool)$pdvSettings['require_customer'] && empty($data['customer_id'])) {
            throw ValidationException::withMessages(['customer_id'=>'Selecione um cliente para concluir a venda no PDV.']);
        }

        if(!(bool)$pdvSettings['allow_discount'] && collect($data['items'])->contains(fn($item)=>(float)($item['discount'] ?? 0)>0)) {
            throw ValidationException::withMessages(['items'=>'Descontos estão desativados nas configurações do PDV.']);
        }

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

        $consumerDocument=$this->normalizeConsumerDocument($data['consumer_document'] ?? null);
        if($consumerDocument!==null && !$this->validCpfCnpj($consumerDocument)) {
            throw ValidationException::withMessages([
                'consumer_document'=>'Informe um CPF ou CNPJ válido para identificar o consumidor.',
            ]);
        }

        $submittedPayments=collect($data['payments'] ?? []);
        if($submittedPayments->isNotEmpty() && !(bool)$pdvSettings['allow_split_payment']) {
            throw ValidationException::withMessages([
                'payments'=>'Pagamento dividido está desativado nas configurações do PDV.',
            ]);
        }

        if($submittedPayments->isEmpty()) {
            if(empty($data['payment_method'])) {
                throw ValidationException::withMessages([
                    'payment_method'=>'Selecione a forma de pagamento.',
                ]);
            }

            $submittedPayments=collect([[
                'payment_method'=>$data['payment_method'],
                'amount'=>number_format($total,2,'.',''),
            ]]);
        }

        $methodCodes=$submittedPayments->pluck('payment_method')->filter()->unique()->values()->all();
        $methods=PaymentMethod::query()
            ->whereIn('code',$methodCodes)
            ->where('is_active',true)
            ->where('pdv_enabled',true)
            ->get()
            ->keyBy('code');

        if($methods->count()!==count($methodCodes)) {
            throw ValidationException::withMessages([
                'payments'=>'Há uma forma de pagamento inválida ou desabilitada no PDV.',
            ]);
        }

        $paymentCents=$submittedPayments->sum(fn($payment)=>(int)round((float)$payment['amount']*100));
        if(abs($paymentCents-$totalCents)>1) {
            throw ValidationException::withMessages([
                'payments'=>'A soma das formas de pagamento deve ser igual ao total da venda.',
            ]);
        }

        $payments=[];
        $cashPaymentTotal=0.0;

        foreach($submittedPayments as $payment) {
            $method=$methods->get($payment['payment_method']);
            if(!$method) continue;

            $amount=round((float)$payment['amount'],2);
            $isCash=$method->kind==='cash';
            if($isCash) $cashPaymentTotal+=$amount;

            $payments[]=[
                'amount'=>number_format($amount,2,'.',''),
                'due_date'=>now()->copy()->addDays((int)$method->settlement_days)->toDateString(),
                'payment_method'=>$method->code,
                'receivable'=>$method->kind==='bank_slip' || $method->settlement_days>0,
            ];
        }

        $cashReceived=(float)($data['cash_received'] ?? 0);
        if($cashPaymentTotal>0 && $cashReceived+0.0001<$cashPaymentTotal) {
            throw ValidationException::withMessages([
                'cash_received'=>'O valor recebido em dinheiro é menor que a parcela em dinheiro.',
            ]);
        }

        $sale=$sales->create([
            'customer_id'=>$data['customer_id'] ?? null,
            'consumer_document'=>$consumerDocument,
            'consumer_name'=>$consumerDocument ? (trim((string)($data['consumer_name'] ?? '')) ?: null) : null,
            'operation_type'=>'sale',
            'source'=>'pdv',
            'operation_date'=>now()->toDateString(),
            'final_consumer'=>true,
            'keyword'=>'PDV',
            'notes'=>$data['notes'] ?? null,
            'items'=>$rows,
            'payments'=>$payments,
        ],(int)$request->user()->id);

        $change=$cashPaymentTotal>0
            ? max(0,$cashReceived-$cashPaymentTotal)
            : 0;

        return redirect()
            ->route('pdv.receipt',$sale)
            ->with('pdv_last_sale',$sale->id)
            ->with('pdv_cash_received',$cashPaymentTotal>0 ? $cashReceived : $total)
            ->with('pdv_change',$change);
    }

    public function openCash(Request $request)
    {
        $data=$request->validate([
            'opening_amount'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'opening_notes'=>['nullable','string','max:1000'],
        ]);

        if(PdvCashSession::query()->where('user_id',$request->user()->id)->open()->exists()) {
            throw ValidationException::withMessages(['opening_amount'=>'Já existe um caixa aberto para este usuário.']);
        }

        PdvCashSession::query()->create([
            'user_id'=>$request->user()->id,
            'opening_amount'=>$data['opening_amount'],
            'opening_notes'=>$data['opening_notes'] ?? null,
            'opened_at'=>now(),
        ]);

        return redirect()->route('pdv.index')->with('success','Caixa aberto.');
    }

    public function closeCash(Request $request)
    {
        $data=$request->validate([
            'closing_amount'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'closing_notes'=>['nullable','string','max:1000'],
        ]);

        $session=PdvCashSession::query()
            ->where('user_id',$request->user()->id)
            ->open()
            ->latest('opened_at')
            ->first();

        if(!$session) {
            throw ValidationException::withMessages(['closing_amount'=>'Não há caixa aberto para fechar.']);
        }

        $session->update([
            'closing_amount'=>$data['closing_amount'],
            'closing_notes'=>$data['closing_notes'] ?? null,
            'closed_at'=>now(),
        ]);

        return redirect()->route('pdv.index')->with('success','Caixa fechado.');
    }

    public function cashMovement(Request $request)
    {
        $data=$request->validate([
            'type'=>['required',Rule::in(['supply','withdrawal'])],
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'reason'=>['required','string','max:255'],
        ]);

        if(!(bool)AppSetting::value('pdv','allow_cash_movements',true)) {
            abort(403,'Movimentações de caixa estão desativadas.');
        }

        $session=PdvCashSession::query()
            ->where('user_id',$request->user()->id)
            ->open()
            ->latest('opened_at')
            ->first();

        if(!$session) {
            throw ValidationException::withMessages([
                'amount'=>'Abra o caixa antes de registrar sangria ou suprimento.',
            ]);
        }

        $amount=round((float)$data['amount'],2);

        if($data['type']==='withdrawal' && $amount>$this->cashExpectedForSession($session)+0.0001) {
            throw ValidationException::withMessages([
                'amount'=>'A sangria não pode ser maior que o saldo esperado em dinheiro.',
            ]);
        }

        PdvCashMovement::query()->create([
            'cash_session_id'=>$session->id,
            'user_id'=>$request->user()->id,
            'type'=>$data['type'],
            'amount'=>number_format($amount,2,'.',''),
            'reason'=>trim($data['reason']),
        ]);

        return redirect()->route('pdv.index')->with(
            'success',
            $data['type']==='supply' ? 'Suprimento registrado.' : 'Sangria registrada.'
        );
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

        $allPaymentMethods=PaymentMethod::query()->orderBy('sort_order')->get(['code','name','kind']);
        $paymentLabels=$allPaymentMethods->pluck('name','code')->all();
        $paymentKinds=$allPaymentMethods->pluck('kind','code')->all();
        $pdvSettings=AppSetting::groupValues('pdv',['receipt_width'=>'80','receipt_copies'=>1]);
        $company=CompanySetting::current();

        $cashReceived=(float)session('pdv_cash_received',(float)$sale->total);
        $change=(float)session('pdv_change',0);

        session()->keep(['pdv_last_sale','pdv_change']);

        return view('pdv.receipt',[
            'sale'=>$sale,
            'paymentLabels'=>$paymentLabels,
            'paymentKinds'=>$paymentKinds,
            'pdvSettings'=>$pdvSettings,
            'company'=>$company,
            'cashReceived'=>$cashReceived,
            'change'=>$change,
        ]);
    }
    private function cashExpectedForSession(PdvCashSession $session): float
    {
        $cashCodes=PaymentMethod::query()->where('kind','cash')->pluck('code');

        $cashSales=(float)SalePayment::query()
            ->whereIn('payment_method',$cashCodes)
            ->whereHas('sale',fn($query)=>$query
                ->where('source','pdv')
                ->where('user_id',$session->user_id)
                ->where('status','completed')
                ->where('completed_at','>=',$session->opened_at)
                ->when($session->closed_at,fn($q)=>$q->where('completed_at','<=',$session->closed_at)))
            ->sum('amount');

        $supply=(float)PdvCashMovement::query()
            ->where('cash_session_id',$session->id)
            ->where('type','supply')
            ->sum('amount');

        $withdrawal=(float)PdvCashMovement::query()
            ->where('cash_session_id',$session->id)
            ->where('type','withdrawal')
            ->sum('amount');

        return round((float)$session->opening_amount+$cashSales+$supply-$withdrawal,2);
    }

    private function normalizeConsumerDocument(?string $document): ?string
    {
        $digits=preg_replace('/\D+/','',(string)$document) ?: '';
        return $digits==='' ? null : $digits;
    }

    private function validCpfCnpj(string $document): bool
    {
        if(strlen($document)===11) {
            if(preg_match('/^(\d)\1{10}$/',$document)) return false;

            for($t=9;$t<11;$t++) {
                $sum=0;
                for($i=0;$i<$t;$i++) {
                    $sum+=(int)$document[$i]*(($t+1)-$i);
                }
                $digit=(10*($sum%11))%11;
                if($digit===10) $digit=0;
                if((int)$document[$t]!==$digit) return false;
            }

            return true;
        }

        if(strlen($document)===14) {
            if(preg_match('/^(\d)\1{13}$/',$document)) return false;

            $weights=[
                [5,4,3,2,9,8,7,6,5,4,3,2],
                [6,5,4,3,2,9,8,7,6,5,4,3,2],
            ];

            foreach($weights as $offset=>$weight) {
                $sum=0;
                foreach($weight as $index=>$factor) {
                    $sum+=(int)$document[$index]*$factor;
                }
                $remainder=$sum%11;
                $digit=$remainder<2 ? 0 : 11-$remainder;
                if((int)$document[12+$offset]!==$digit) return false;
            }

            return true;
        }

        return false;
    }

}
