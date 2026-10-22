<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Models\PdvCashMovement;
use App\Models\PdvCashSession;
use App\Models\PdvSuspendedSale;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\Sale;
use App\Services\InventoryService;
use App\Services\SalesService;
use App\Services\PdvCashService;
use App\Models\SaleReturn;
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

        $cashExpected=$cashSession ? PdvCashService::currency(app(PdvCashService::class)->breakdown($cashSession)['expected']) : null;
        $cashMovements=$cashSession
            ? PdvCashMovement::query()
                ->where('cash_session_id',$cashSession->id)
                ->latest('id')
                ->limit(8)
                ->get()
            : collect();

        $suspendedSales=PdvSuspendedSale::query()
            ->with('user:id,name')
            ->whereNull('resumed_at')
            ->latest('suspended_at')
            ->limit(20)
            ->get();

        $nfceContingencyActive=(bool)AppSetting::value('nfce','offline_contingency_active',false);
        $nfceContingencyReason=(string)AppSetting::value('nfce','offline_contingency_reason','');
        $nfceContingencyStartedAt=AppSetting::value('nfce','offline_contingency_started_at',null);

        $cancelableNfceJobs=FiscalDocumentJob::query()
            ->with('sale:id,total,completed_at')
            ->where('document_type','nfce')
            ->where('status','authorized')
            ->whereNull('cancelled_at')
            ->where(function($q){
                $q->whereNull('cancellation_status')->orWhere('cancellation_status','failed');
            })
            ->latest('authorized_at')
            ->limit(12)
            ->get();

        // A última venda deve vir do banco: flash/session é consumida na
        // visualização do comprovante e não sobrevive ao retorno ao PDV.
        $lastCompletedPdvSale=Sale::query()
            ->where('source','pdv')
            ->where('operation_type','sale')
            ->where('status','completed')
            ->where('user_id',$request->user()->id)
            ->latest('id')
            ->first(['id','total','completed_at','created_at']);

        $lastPdvNfceDocument=$lastCompletedPdvSale
            ? FiscalDocumentJob::query()
                ->where('sale_id',$lastCompletedPdvSale->id)
                ->where('document_type','nfce')
                ->latest('id')
                ->first()
            : null;

        // A confirmação da venda é exibida dentro do PDV, apenas na primeira
        // visita após a finalização. Nunca consulta outra venda por engano.
        $receiptModalSaleId=(int)$request->session()->get('pdv_receipt_sale',0);
        $receiptModalSale=$receiptModalSaleId>0
            ? Sale::query()
                ->whereKey($receiptModalSaleId)
                ->where('source','pdv')
                ->where('operation_type','sale')
                ->where('status','completed')
                ->where('user_id',$request->user()->id)
                ->with(['items','payments','customer:id,name','user:id,name'])
                ->first([
                    'id','customer_id','user_id','subtotal','discount_total','total',
                    'cash_received','change_amount','consumer_document','consumer_name',
                    'notes','completed_at','status',
                ])
            : null;
        $receiptModalDetails=$receiptModalSale
            ? $this->receiptModalDetails($receiptModalSale)
            : [
                'receiptModalCompany'=>null,
                'receiptModalPaymentLabels'=>[],
                'receiptModalPaymentKinds'=>[],
                'receiptModalNfceDocument'=>null,
            ];

        $lastPdvNfceStatusLabel=$lastPdvNfceDocument ? match($lastPdvNfceDocument->status) {
            'prepared'=>'preparada',
            'processing'=>'processando',
            'pending'=>'pendente de consulta',
            'authorized'=>'autorizada',
            'rejected'=>'rejeitada',
            'offline_signed'=>'em contingência (sem autorização)',
            'offline_sending'=>'aguardando autorização após contingência',
            'offline_print_pending'=>'DANFE de contingência pendente',
            'cancelled'=>'cancelada',
            default=>'verificar',
        } : 'ainda não preparada';

        return view('pdv.index',[
            'lastCompletedPdvSale'=>$lastCompletedPdvSale,
            'lastPdvNfceDocument'=>$lastPdvNfceDocument,
            'lastPdvNfceStatusLabel'=>$lastPdvNfceStatusLabel,
            'receiptModalSale'=>$receiptModalSale,
            ...$receiptModalDetails,
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
            'refundReturns'=>$cashSession ? SaleReturn::query()
                ->where('status','completed')->whereHas('sale',fn($q)=>$q->where('source','pdv'))
                ->latest('id')->limit(20)->get(['id','sale_id','total']) : collect(),
            'refundSales'=>$cashSession ? Sale::query()
                ->where('source','pdv')->where('status','cancelled')
                ->latest('id')->limit(20)->get(['id','total']) : collect(),
            'suspendedSales'=>$suspendedSales,
            'nfceContingencyActive'=>$nfceContingencyActive,
            'nfceContingencyReason'=>$nfceContingencyReason,
            'nfceContingencyStartedAt'=>$nfceContingencyStartedAt,
            'cancelableNfceJobs'=>$cancelableNfceJobs,
            'allowNegativeStock'=>(bool)AppSetting::value(
                'inventory',
                'allow_negative_stock',
                AppSetting::value('operations','allow_negative_stock',false)
            ),
        ]);
    }

    /**
     * Consulta de vendas do próprio PDV, sem abandonar a frente de caixa.
     * Por padrão o período é o dia atual; histórico limitado a 90 dias
     * por consulta para proteger o desempenho do caixa.
     */
    public function salesHistory(Request $request)
    {
        $data=$request->validate([
            'from'=>['nullable','date_format:Y-m-d'],
            'to'=>['nullable','date_format:Y-m-d'],
            'status'=>['nullable',Rule::in(['all','completed','cancelled'])],
            'operator'=>['nullable',Rule::in(['all','mine'])],
            'q'=>['nullable','string','max:100'],
            'page'=>['nullable','integer','min:1','max:10000'],
        ]);

        $today=now()->startOfDay();
        $from=\Carbon\Carbon::createFromFormat('!Y-m-d',$data['from'] ?? $today->toDateString());
        $to=\Carbon\Carbon::createFromFormat('!Y-m-d',$data['to'] ?? $today->toDateString());
        if ($to->lt($from) || $from->diffInDays($to)>89) {
            throw ValidationException::withMessages([
                'from'=>'Selecione um período válido de até 90 dias.',
            ]);
        }

        $start=$from->startOfDay()->toDateTimeString();
        $endExclusive=$to->copy()->addDay()->startOfDay()->toDateTimeString();
        $status=$data['status'] ?? 'all';
        $operator=$data['operator'] ?? 'all';
        $term=trim((string)($data['q'] ?? ''));

        $query=Sale::query()
            ->with(['customer:id,name','user:id,name'])
            ->where('source','pdv')
            ->where('operation_type','sale')
            ->where(function($q) use($from,$to,$start,$endExclusive){
                // Intervalo semiaberto funciona para DATE (MySQL) e para
                // os snapshots DATE serializados como DATETIME (SQLite).
                // A comparação direta preserva o uso do índice da coluna.
                $q->where('operation_date','>=',$from->toDateString())
                  ->where('operation_date','<',$to->copy()->addDay()->toDateString())
                  ->orWhere(function($legacy) use($start,$endExclusive){
                      $legacy->whereNull('operation_date')
                          ->where('created_at','>=',$start)
                          ->where('created_at','<',$endExclusive);
                  });
            })
            ->when($status!=='all',fn($q)=>$q->where('status',$status))
            ->when($operator==='mine',fn($q)=>$q->where('user_id',$request->user()->id))
            ->when($term!=='',function($q) use($term){
                $q->where(function($search) use($term){
                    if(preg_match('/^#?\\d+$/',$term)){
                        $search->orWhere('id',(int)ltrim($term,'#'));
                    }
                    $escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term);
                    $like='%'.$escaped.'%';
                    $search->orWhere('consumer_name','like',$like)
                        ->orWhere('consumer_document','like',$like)
                        ->orWhereHas('customer',fn($customer)=>$customer->where('name','like',$like))
                        ->orWhereHas('user',fn($user)=>$user->where('name','like',$like));
                });
            })
            ->orderByDesc('operation_date')
            ->orderByDesc('id');

        $page=$query->paginate(20,['id','customer_id','consumer_name','user_id','total','status','operation_date','completed_at','created_at']);
        $documents=FiscalDocumentJob::query()
            ->where('document_type','nfce')
            ->whereIn('sale_id',$page->getCollection()->pluck('id')->all())
            ->orderByDesc('id')
            ->get(['id','sale_id','status','emission_mode','xml_path'])
            ->unique('sale_id')
            ->keyBy('sale_id');

        $items=$page->getCollection()->map(function(Sale $sale) use($documents){
            $document=$documents->get($sale->id);
            $fiscalStatus=$document ? match($document->status) {
                'authorized'=>'Autorizada',
                'cancelled'=>'Cancelada',
                'rejected'=>'Rejeitada',
                'processing'=>'Processando',
                'pending'=>'Pendente',
                'offline_signed'=>'Contingência',
                'prepared'=>'Preparada',
                default=>'A verificar',
            } : 'Não preparada';

            return [
                'id'=>$sale->id,
                'number'=>str_pad((string)$sale->id,5,'0',STR_PAD_LEFT),
                'date'=>($sale->completed_at ?? $sale->created_at)?->format('d/m/Y H:i') ?? '—',
                'customer'=>$sale->customer?->name ?: ($sale->consumer_name ?: 'Consumidor não identificado'),
                'operator'=>$sale->user?->name ?: '—',
                'total'=>(float)$sale->total,
                'status'=>$sale->status,
                'status_label'=>match($sale->status) {
                    'completed'=>'Concluída','cancelled'=>'Cancelada',default=>'Pendente',
                },
                'fiscal_status'=>$fiscalStatus,
                'fiscal_code'=>$document?->status ?: 'none',
                'receipt_url'=>route('pdv.receipt',['sale'=>$sale->id,'print'=>0]),
                'receipt_preview_url'=>route('pdv.sales.receipt.preview',$sale),
                'danfe_url'=>$document && (
                    ($document->status==='authorized'
                        && in_array((string)$document->xml_path,[
                            'fiscal/nfce/'.$document->id.'/authorized.xml',
                            'fiscal/nfce/'.$document->id.'/authorized-recovered.xml',
                        ],true))
                    || ($document->emission_mode==='offline'
                        && in_array($document->status,['offline_signed','offline_sending','pending'],true)
                        && (string)$document->xml_path==='fiscal/nfce/'.$document->id.'/signed.xml')
                ) ? route('pdv.nfce.danfe',$document) : null,
            ];
        })->values();

        return response()->json([
            'items'=>$items,
            'pagination'=>[
                'page'=>$page->currentPage(),
                'pages'=>$page->lastPage(),
                'per_page'=>$page->perPage(),
                'total'=>$page->total(),
                'from'=>$page->firstItem(),
                'to'=>$page->lastItem(),
            ],
        ])->header('Cache-Control','no-store, private');
    }

    /** Logo da empresa por rota privada; funciona sem link simbólico do Laragon. */
    public function companyLogo()
    {
        $path=CompanySetting::current()->logo_path;
        $disk=Storage::disk('public');
        if (!$path || !$disk->exists($path)) abort(404);
        $size=$disk->size($path);
        $mime=$disk->mimeType($path);
        abort_unless($size>0 && $size<=4*1024*1024
            && in_array($mime,['image/png','image/jpeg','image/webp'],true),404);

        return response($disk->get($path),200,[
            'Content-Type'=>$mime,
            'Content-Length'=>(string)$size,
            'Cache-Control'=>'private, max-age=300',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    /** Retorna o mesmo cupom do pós-venda para o histórico, sem alterar a venda. */
    public function salesReceiptPreview(Sale $sale)
    {
        $receiptModalSale=Sale::query()
            ->whereKey($sale->id)
            ->where('source','pdv')
            ->where('operation_type','sale')
            ->whereIn('status',['completed','cancelled'])
            ->with(['items','payments','customer:id,name','user:id,name'])
            ->firstOrFail();

        return response()->view('pdv.partials.receipt-modal',[
            'receiptModalSale'=>$receiptModalSale,
            'receiptModalIsHistory'=>true,
            ...$this->receiptModalDetails($receiptModalSale),
        ])->header('Cache-Control','no-store, private')
          ->header('X-Content-Type-Options','nosniff');
    }

    /** Fonte única dos dados do cupom no PDV e no histórico. */
    private function receiptModalDetails(Sale $sale): array
    {
        $paymentMethods=PaymentMethod::query()
            ->whereIn('code',$sale->payments->pluck('payment_method')->all())
            ->get(['code','name','kind']);

        return [
            'receiptModalCompany'=>CompanySetting::current(),
            'receiptModalPaymentLabels'=>$paymentMethods->pluck('name','code')->all(),
            'receiptModalPaymentKinds'=>$paymentMethods->pluck('kind','code')->all(),
            'receiptModalNfceDocument'=>FiscalDocumentJob::query()
                ->where('sale_id',$sale->id)
                ->where('document_type','nfce')
                ->latest('id')->first(),
        ];
    }

    public function search(Request $request)
    {
        $term=trim((string)$request->query('q',''));

        if(mb_strlen($term)<1) {
            return response()->json(['items'=>[]]);
        }

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
            ->limit(30)
            ->get();

        return response()->json([
            'items'=>$products->map(fn(Product $product)=>[
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
            ])->values(),
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
            'payments.*.integration_type'=>['nullable',Rule::in(['1','2'])],
            'payments.*.transaction_document'=>['nullable','string','max:20'],
            'payments.*.transaction_state'=>['nullable','string',Rule::in([
                'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG',
                'PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'
            ])],
            'payments.*.institution_document'=>['nullable','string','max:20'],
            'payments.*.card_brand'=>['nullable',Rule::in(\App\Support\NFCeCardBrands::codes())],
            'payments.*.authorization_code'=>['nullable','string','max:128'],
            'payments.*.beneficiary_document'=>['nullable','string','max:20'],
            'payments.*.terminal_id'=>['nullable','string','max:40'],
            'cash_received'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'notes'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1','max:150'],
            'items.*.item_type'=>['required',Rule::in(['product'])],
            'items.*.product_id'=>['required','integer','exists:products,id'],
            'items.*.quantity'=>['required','numeric','gt:0','max:9999999999','decimal:0,3'],
            'items.*.discount'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
        ]);

        $pdvSettings=AppSetting::groupValues('pdv',[
            'require_customer'=>false,
            'allow_discount'=>true,
            'require_cash_opening'=>false,
            'allow_split_payment'=>true,
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
            ->pluck('product_id')
            ->filter()
            ->map(fn($id)=>(int)$id)
            ->unique()
            ->all();

        $products=Product::query()
            ->whereIn('id',$productIds)
            ->get()
            ->keyBy('id');

        $rows=[];
        $totalCents=0;

        foreach($data['items'] as $index=>$row) {
            $quantity=(float)$row['quantity'];
            $discountCents=(int)round((float)($row['discount'] ?? 0)*100);

            $item=$products->get((int)$row['product_id']);
            if(!$item || !$item->is_active) {
                throw ValidationException::withMessages(["items.$index.product_id"=>'Produto inválido ou inativo.']);
            }

            $price=(float)$item->sale_price;
            $grossCents=(int)round($price*100*InventoryService::toMills($quantity)/1000);

            if($discountCents>$grossCents) {
                throw ValidationException::withMessages(["items.$index.discount"=>'O desconto é maior que o valor do item.']);
            }

            $totalCents += $grossCents-$discountCents;

            $rows[]=[
                'item_type'=>'product',
                'product_id'=>$item->id,
                'service_id'=>null,
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
        if($paymentCents!==$totalCents) {
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

            $transactionDocument=$this->normalizeCnpj($payment['transaction_document'] ?? null);
            $institutionDocument=$this->normalizeCnpj($payment['institution_document'] ?? null);
            $beneficiaryDocument=$this->normalizeCnpj($payment['beneficiary_document'] ?? null);
            $transactionState=strtoupper(trim((string)($payment['transaction_state'] ?? '')));

            $fiscalKind=$method->fiscalKind();
            if(in_array($fiscalKind,['card','credit_card','debit_card'],true)
                && !in_array((string)($payment['integration_type'] ?? ''),['1','2'],true)) {
                throw ValidationException::withMessages([
                    'payments'=>'Informe se o pagamento com cartão é integrado/TEF ou POS não integrado.',
                ]);
            }

            if(($transactionDocument===null) xor ($transactionState==='')) {
                throw ValidationException::withMessages([
                    'payments'=>'CNPJ transacional e UF do pagamento devem ser informados juntos.',
                ]);
            }

            foreach([
                'CNPJ transacional'=>$transactionDocument,
                'CNPJ da instituição de pagamento'=>$institutionDocument,
                'CNPJ do beneficiário'=>$beneficiaryDocument,
            ] as $label=>$document) {
                if($document!==null && (strlen($document)!==14 || !$this->validCpfCnpj($document))) {
                    throw ValidationException::withMessages([
                        'payments'=>$label.' inválido.',
                    ]);
                }
            }

            $isCard=in_array($fiscalKind,['card','credit_card','debit_card'],true);
            $electronic=$isCard || $fiscalKind==='pix';
            if($fiscalKind==='pix' && trim((string)($payment['card_brand'] ?? ''))!==''){
                throw ValidationException::withMessages([
                    'payments'=>'Bandeira de cartão não se aplica ao PIX.',
                ]);
            }
            $integration=(string)($payment['integration_type'] ?? '');
            if($isCard && $integration==='1'
                && (!$institutionDocument || !trim((string)($payment['authorization_code'] ?? '')))) {
                throw ValidationException::withMessages([
                    'payments'=>'Cartão integrado exige CNPJ da adquirente e código de autorização.',
                ]);
            }
            if(!$electronic && (
                $integration!=='' || $transactionDocument!==null || $transactionState!==''
                || $institutionDocument!==null || $beneficiaryDocument!==null
                || trim((string)($payment['card_brand'] ?? ''))!==''
                || trim((string)($payment['authorization_code'] ?? ''))!==''
                || trim((string)($payment['terminal_id'] ?? ''))!==''
            )) {
                throw ValidationException::withMessages([
                    'payments'=>'Dados eletrônicos incompatíveis com a forma de pagamento selecionada.',
                ]);
            }

            $payments[]=[
                'amount'=>number_format($amount,2,'.',''),
                'due_date'=>now()->copy()->addDays((int)$method->settlement_days)->toDateString(),
                'payment_method'=>$method->code,
                'integration_type'=>$payment['integration_type'] ?? null,
                'transaction_document'=>$transactionDocument,
                'transaction_state'=>$transactionState!=='' ? $transactionState : null,
                'institution_document'=>$institutionDocument,
                'card_brand'=>$payment['card_brand'] ?? null,
                'authorization_code'=>trim((string)($payment['authorization_code'] ?? '')) ?: null,
                'beneficiary_document'=>$beneficiaryDocument,
                'terminal_id'=>trim((string)($payment['terminal_id'] ?? '')) ?: null,
                'receivable'=>$method->kind==='bank_slip' || $method->settlement_days>0,
            ];
        }

        $cashReceived=(float)($data['cash_received'] ?? 0);
        if($cashPaymentTotal>0 && $cashReceived+0.0001<$cashPaymentTotal) {
            throw ValidationException::withMessages([
                'cash_received'=>'O valor recebido em dinheiro é menor que a parcela em dinheiro.',
            ]);
        }

        $change=$cashPaymentTotal>0
            ? max(0,$cashReceived-$cashPaymentTotal)
            : 0;

        $sale=$sales->create([
            'customer_id'=>$data['customer_id'] ?? null,
            'consumer_document'=>$consumerDocument,
            'consumer_name'=>$consumerDocument ? (trim((string)($data['consumer_name'] ?? '')) ?: null) : null,
            'cash_received'=>$cashPaymentTotal>0 ? number_format($cashReceived,2,'.','') : null,
            'change_amount'=>number_format($change,2,'.',''),
            'operation_type'=>'sale',
            'source'=>'pdv',
            'operation_date'=>now()->toDateString(),
            'final_consumer'=>true,
            'keyword'=>'PDV',
            'notes'=>$data['notes'] ?? null,
            'items'=>$rows,
            'payments'=>$payments,
        ],(int)$request->user()->id);

        return redirect()
            ->route('pdv.index')
            ->with('pdv_receipt_sale',$sale->id)
            ->with('pdv_last_sale',$sale->id)
            ->with('pdv_cash_received',$cashPaymentTotal>0 ? $cashReceived : $total)
            ->with('pdv_change',$change);
    }

    public function suspendSale(Request $request)
    {
        $data=$request->validate([
            'label'=>['nullable','string','max:190'],
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'consumer_document'=>['nullable','string','max:20'],
            'consumer_name'=>['nullable','string','max:190'],
            'payment_method'=>['nullable','string','max:40'],
            'cash_received'=>['nullable','numeric','min:0','max:9999999999.99'],
            'notes'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1','max:150'],
            'items.*.type'=>['required',Rule::in(['product'])],
            'items.*.id'=>['required','integer'],
            'items.*.quantity'=>['required','numeric','gt:0','max:9999999999'],
            'items.*.discount'=>['nullable','numeric','min:0','max:9999999999.99'],
            'payments'=>['nullable','array','max:10'],
        ]);

        $snapshot=$this->buildSuspendedSnapshot($data);

        if(empty($snapshot['items'])) {
            throw ValidationException::withMessages(['items'=>'Não há itens válidos para suspender.']);
        }

        $suspended=PdvSuspendedSale::query()->create([
            'user_id'=>$request->user()->id,
            'label'=>trim((string)($data['label'] ?? '')) ?: null,
            'payload'=>$snapshot,
            'total'=>number_format((float)$snapshot['total'],2,'.',''),
            'item_count'=>count($snapshot['items']),
            'suspended_at'=>now(),
        ]);

        return response()->json([
            'message'=>'Venda suspensa.',
            'id'=>$suspended->id,
        ],201);
    }

    public function resumeSale(Request $request, PdvSuspendedSale $suspendedSale)
    {
        if($suspendedSale->resumed_at) {
            return response()->json(['message'=>'Esta venda suspensa já foi recuperada.'],409);
        }

        $snapshot=$this->refreshSuspendedSnapshot($suspendedSale->payload ?? []);

        if(empty($snapshot['items'])) {
            return response()->json(['message'=>'Nenhum item da venda suspensa está disponível atualmente.'],422);
        }

        $suspendedSale->update(['resumed_at'=>now()]);

        return response()->json([
            'message'=>'Venda recuperada.',
            'snapshot'=>$snapshot,
        ]);
    }

    public function discardSuspendedSale(PdvSuspendedSale $suspendedSale)
    {
        if($suspendedSale->resumed_at) {
            return response()->json(['message'=>'A venda já foi recuperada.'],409);
        }

        $suspendedSale->delete();

        return response()->json(['message'=>'Venda suspensa descartada.']);
    }

    public function setNfceContingency(Request $request)
    {
        $data=$request->validate([
            'action'=>['required',Rule::in(['start','stop'])],
            'reason'=>['nullable','string','max:255'],
        ]);

        if(!(bool)AppSetting::value('fiscal','enabled',false) || !(bool)AppSetting::value('nfce','enabled',false)) {
            throw ValidationException::withMessages([
                'action'=>'Habilite os recursos fiscais e a NFC-e antes de usar contingência.',
            ]);
        }

        if($data['action']==='start') {
            // Em produção, não ativar um modo que o worker fiscal bloquearia.
            // A venda não deve ser concluída com contingência indisponível.
            app(\App\Services\Fiscal\NFCeProductionGate::class)
                ->assertAllowed(app(\App\Services\Fiscal\FiscalDocumentSettings::class)->environment('nfce'));
            $reason=trim((string)($data['reason'] ?? ''));
            if(mb_strlen($reason)<15) {
                throw ValidationException::withMessages([
                    'reason'=>'Informe um motivo de contingência com pelo menos 15 caracteres.',
                ]);
            }

            AppSetting::put('nfce','offline_contingency_active',true);
            AppSetting::put('nfce','offline_contingency_reason',$reason);
            AppSetting::put('nfce','offline_contingency_started_at',now()->toIso8601String());

            return redirect()->route('pdv.index')->with('warning','Contingência offline NFC-e ativada para novas vendas.');
        }

        AppSetting::put('nfce','offline_contingency_active',false);
        AppSetting::put('nfce','offline_contingency_reason',null);
        AppSetting::put('nfce','offline_contingency_started_at',null);

        return redirect()->route('pdv.index')->with('success','Contingência NFC-e encerrada para novas vendas.');
    }

    public function requestNfceCancellation(Request $request,FiscalDocumentJob $fiscalJob)
    {
        abort_unless($fiscalJob->document_type==='nfce',404);
        return app(\App\Http\Controllers\NFCeFiscalEventsController::class)->cancel(
            $request,$fiscalJob,app(\App\Services\Fiscal\NFCeCancellationService::class)
        );
    }

    public function openCash(Request $request, PdvCashService $cash)
    {
        $data=$request->validate([
            'opening_amount'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'opening_notes'=>['nullable','string','max:1000'],
        ]);
        $cash->open((int)$request->user()->id,(string)$data['opening_amount'],$data['opening_notes'] ?? null);
        return redirect()->route('pdv.index')->with('success','Caixa aberto.');
    }

    public function closeCash(Request $request, PdvCashService $cash)
    {
        $data=$request->validate([
            'closing_amount'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'closing_notes'=>['nullable','string','max:1000'],
        ]);
        $session=$cash->close((int)$request->user()->id,(string)$data['closing_amount'],$data['closing_notes'] ?? null);
        return redirect()->route('pdv.cash.report',$session)
            ->with('success','Caixa fechado. Confira o relatório do turno.');
    }

    public function cashMovement(Request $request, PdvCashService $cash)
    {
        $data=$request->validate([
            'type'=>['required',Rule::in(['supply','withdrawal','refund'])],
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'reason'=>['required','string','max:255'],
            'sale_id'=>['nullable','integer','exists:sales,id'],
            'sale_return_id'=>['nullable','integer','exists:sale_returns,id'],
        ]);
        if(!(bool)AppSetting::value('pdv','allow_cash_movements',true)) {
            abort(403,'Movimentações de caixa estão desativadas.');
        }
        $type=$data['type'];
        $cash->movement((int)$request->user()->id,$type,(string)$data['amount'],$data['reason'],
            isset($data['sale_id'])?(int)$data['sale_id']:null,
            isset($data['sale_return_id'])?(int)$data['sale_return_id']:null);
        return redirect()->route('pdv.index')->with('success',match($type) {
            'supply'=>'Suprimento registrado.',
            'withdrawal'=>'Sangria registrada.',
            default=>'Reembolso em dinheiro registrado.',
        });
    }

    /**
     * Recupera a preparação fiscal de uma venda já finalizada pelo PDV.
     * Não conclui outra venda nem movimenta estoque/financeiro.
     */
    public function prepareNfce(Request $request, Sale $sale, \App\Services\FiscalPreparationService $fiscalPreparation)
    {
        abort_unless($request->user()?->canAccess('fiscal'), 403);
        abort_unless($sale->source === 'pdv', 404);

        $existing = FiscalDocumentJob::query()
            ->where('sale_id', $sale->id)
            ->where('document_type', 'nfce')
            ->first();
        if ($existing) {
            return redirect()->route('fiscal.show', $existing);
        }

        if ($sale->status !== 'completed' || $sale->operation_type !== 'sale') {
            return redirect()->route('pdv.receipt', ['sale' => $sale->id, 'print' => 0, 'status' => 1])
                ->with('error', 'A NFC-e só pode ser preparada para uma venda concluída.');
        }

        if (!(bool) AppSetting::value('fiscal', 'enabled', false)
            || !(bool) AppSetting::value('nfce', 'enabled', false)) {
            return redirect()->route('pdv.receipt', ['sale' => $sale->id, 'print' => 0, 'status' => 1])
                ->with('error', 'Habilite Fiscal e NFC-e nas configurações antes de preparar este documento.');
        }

        $sale->loadMissing('items');
        if ($sale->items->isEmpty() || !$sale->items->every(
            fn ($item) => $item->item_type === 'product' && $item->product_id
        )) {
            return redirect()->route('pdv.receipt', ['sale' => $sale->id, 'print' => 0, 'status' => 1])
                ->with('error', 'Esta venda contém itens que não podem ser emitidos como NFC-e de produtos.');
        }

        try {
            // Idempotência garantida por prepareForSale: verifica documento por
            // venda antes de reservar outro número de NFC-e.
            $fiscalPreparation->prepareForSale($sale);
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::error('Falha ao recuperar preparação da NFC-e do PDV', [
                'sale_id' => $sale->id,
                'error' => $error->getMessage(),
            ]);

            return redirect()->route('pdv.receipt', ['sale' => $sale->id, 'print' => 0, 'status' => 1])
                ->with('error', 'Não foi possível preparar a NFC-e desta venda. Consulte o log do Lumeron.');
        }

        $document = FiscalDocumentJob::query()
            ->where('sale_id', $sale->id)
            ->where('document_type', 'nfce')
            ->first();

        return $document
            ? redirect()->route('fiscal.show', $document)
            : redirect()->route('pdv.receipt', ['sale' => $sale->id, 'print' => 0, 'status' => 1])
                ->with('error', 'A NFC-e não foi preparada. Confira se o módulo fiscal está habilitado.');
    }

    public function receipt(Request $request, Sale $sale, \App\Services\SaleReceiptPresenter $presenter,
        \App\Services\SaleReceiptPdfService $pdf)
    {
        abort_unless($sale->source==='pdv',404);

        // Histórico do PDV (?print=0): prévia pelo leitor nativo de PDF.
        // Erros fiscais (?status=1) mantêm a página informativa sem imprimir.
        // Finalização da venda (sem o parâmetro) preserva impressão e retorno ao caixa.
        if ($request->query('print')==='0' && !$request->boolean('status')) {
            try {
                $bytes=$pdf->render($sale);
            } catch (\RuntimeException $e) {
                abort(503,$e->getMessage());
            }
            $name='Comprovante-Venda-'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT).'.pdf';
            $mode=$request->boolean('download')?'attachment':'inline';
            return response($bytes,200,[
                'Content-Type'=>'application/pdf',
                'Content-Disposition'=>$mode.'; filename="'.$name.'"',
                'Cache-Control'=>'private, no-store, max-age=0',
                'X-Content-Type-Options'=>'nosniff',
            ]);
        }

        return view('pdv.receipt',$presenter->data($sale,true));
    }
    private function cashExpectedForSession(PdvCashSession $session): float
    {
        return app(PdvCashService::class)->breakdown($session)['expected']/100;
    }

    private function buildSuspendedSnapshot(array $data): array
    {
        $items=$this->refreshSuspendedItems($data['items'] ?? []);

        $total=collect($items)->sum(
            fn($item)=>max(0,((float)$item['price']*(float)$item['quantity'])-(float)$item['discount'])
        );

        return [
            'customer_id'=>$data['customer_id'] ?? null,
            'consumer_document'=>$this->normalizeConsumerDocument($data['consumer_document'] ?? null),
            'consumer_name'=>trim((string)($data['consumer_name'] ?? '')) ?: null,
            'payment_method'=>$data['payment_method'] ?? null,
            'payments'=>array_values($data['payments'] ?? []),
            'cash_received'=>(float)($data['cash_received'] ?? 0),
            'notes'=>trim((string)($data['notes'] ?? '')) ?: null,
            'items'=>$items,
            'total'=>round($total,2),
        ];
    }

    private function refreshSuspendedSnapshot(array $payload): array
    {
        $items=$this->refreshSuspendedItems($payload['items'] ?? []);
        $total=collect($items)->sum(
            fn($item)=>max(0,((float)$item['price']*(float)$item['quantity'])-(float)$item['discount'])
        );

        $payload['items']=$items;
        $payload['total']=round($total,2);

        if(!empty($payload['customer_id'])) {
            $customer=Customer::query()
                ->where('id',$payload['customer_id'])
                ->where('is_customer',true)
                ->first();

            if(!$customer) $payload['customer_id']=null;
        }

        return $payload;
    }

    private function refreshSuspendedItems(array $rows): array
    {
        $productRows=collect($rows)->filter(fn($row)=>($row['type'] ?? null)==='product');
        $productIds=$productRows->pluck('id')->map(fn($id)=>(int)$id)->unique()->all();

        $products=Product::query()
            ->whereIn('id',$productIds)
            ->where('is_active',true)
            ->get()
            ->keyBy('id');

        return $productRows->map(function($row) use($products) {
            $item=$products->get((int)($row['id'] ?? 0));
            if(!$item) return null;

            return [
                'type'=>'product',
                'id'=>$item->id,
                'name'=>$item->name,
                'code'=>$item->sku,
                'ean'=>$item->ean_gtin,
                'price'=>(float)$item->sale_price,
                'stock'=>(float)$item->stock_quantity,
                'unit'=>$item->unit,
                'control_stock'=>(bool)$item->control_stock,
                'quantity'=>max(0.001,(float)($row['quantity'] ?? 1)),
                'discount'=>max(0,(float)($row['discount'] ?? 0)),
                'image'=>$item->image_path ? Storage::disk('public')->url($item->image_path) : null,
            ];
        })->filter()->values()->all();
    }

    private function normalizeCnpj(?string $document): ?string
    {
        return $this->normalizeTaxDocument($document);
    }

    private function normalizeConsumerDocument(?string $document): ?string
    {
        return $this->normalizeTaxDocument($document);
    }

    private function normalizeTaxDocument(?string $document): ?string
    {
        $value=strtoupper((string)$document);
        $value=preg_replace('/[^A-Z0-9]/','',$value) ?: '';
        return $value==='' ? null : $value;
    }

    private function validCpfCnpj(string $document): bool
    {
        $document=$this->normalizeTaxDocument($document) ?? '';

        if(strlen($document)===11 && ctype_digit($document)) {
            if(preg_match('/^(\\d)\\1{10}$/',$document)) return false;

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

        if(strlen($document)===14 && preg_match('/^[A-Z0-9]{12}[0-9]{2}$/',$document)) {
            if(preg_match('/^(.)\\1{13}$/',$document)) return false;

            $base=substr($document,0,12);
            $first=$this->cnpjCheckDigit($base,[5,4,3,2,9,8,7,6,5,4,3,2]);
            $second=$this->cnpjCheckDigit($base.$first,[6,5,4,3,2,9,8,7,6,5,4,3,2]);

            return substr($document,-2)===(string)$first.(string)$second;
        }

        return false;
    }

    private function cnpjCheckDigit(string $base,array $weights): int
    {
        $sum=0;

        foreach(str_split($base) as $index=>$character) {
            $value=ord($character)-48;
            $sum+=$value*$weights[$index];
        }

        $remainder=$sum%11;
        return $remainder<2 ? 0 : 11-$remainder;
    }

}
