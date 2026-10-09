<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessNFCeJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Fiscal\FiscalDocumentSettings;
use App\Services\Fiscal\NFCePreflightService;
use App\Services\FiscalPreparationService;
use App\Services\SalesService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class NFCeManualController extends Controller
{
    private const PAYMENT_KINDS=[
        'cash','money','pix','credit_card','debit_card',
        'bank_slip','bank_transfer','transfer',
    ];

    public function create(Request $request)
    {
        $sales=Sale::query()
            ->with('customer:id,name,document')
            ->where('operation_type','sale')
            ->where('status','completed')
            ->whereDoesntHave('returns',fn($query)=>$query->where('status','completed'))
            ->whereHas('items',fn($query)=>$query->where('item_type','product')->whereNotNull('product_id'))
            ->whereDoesntHave('items',fn($query)=>$query
                ->where('item_type','!=','product')->orWhereNull('product_id'))
            ->whereNotIn('id',FiscalDocumentJob::query()
                ->select('sale_id')->whereNotNull('sale_id'))
            ->latest('id')->limit(100)->get(['id','customer_id','total','operation_date']);

        $methods=PaymentMethod::query()->where('is_active',true)
            ->whereIn('kind',self::PAYMENT_KINDS)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['code','name','kind']);

        return view('fiscal.nfce.create',[
            'company'=>CompanySetting::current(),
            'settings'=>app(FiscalDocumentSettings::class),
            'products'=>Product::query()->where('is_active',true)
                ->orderBy('name')->get(['id','name','sku','ean_gtin','sale_price','unit','stock_quantity','control_stock']),
            'customers'=>Customer::query()->orderBy('name')
                ->get(['id','name','document','final_consumer']),
            'sales'=>$sales,
            'methods'=>$methods,
            'canCreateSale'=>$request->user()->canAccess('sales'),
        ]);
    }

    public function store(
        Request $request,
        SalesService $salesService,
        FiscalPreparationService $fiscalPreparation,
        NFCePreflightService $preflight,
        FiscalDocumentSettings $settings
    ) {
        if (!$settings->enabled('nfce')) {
            throw ValidationException::withMessages([
                'mode'=>'Ative a emissão de NFC-e nas Configurações.',
            ]);
        }

        $base=$request->validate([
            'mode'=>['required',Rule::in(['new','existing'])],
            'submit_mode'=>['required',Rule::in(['save','issue'])],
            'sale_id'=>['required_if:mode,existing','nullable','integer','exists:sales,id'],
        ]);

        if ($base['mode']==='existing') {
            $sale=Sale::query()->findOrFail((int)$base['sale_id']);
            $job=$fiscalPreparation->prepareNfceForSale($sale);
            return $this->finish($job,$base['submit_mode'],$preflight);
        }

        abort_unless($request->user()->canAccess('sales'),403);

        $data=$request->validate([
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'consumer_document'=>['nullable','string','max:20'],
            'consumer_name'=>['nullable','string','max:190'],
            'presence'=>['required',Rule::in(['presential'])],
            'notes'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1','max:100'],
            'items.*.product_id'=>['required','integer',Rule::exists('products','id')->where('is_active',true)],
            'items.*.quantity'=>['required','numeric','gt:0','max:9999999999','decimal:0,3'],
            'items.*.unit_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'items.*.discount'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'payments'=>['required','array','min:1','max:10'],
            'payments.*.payment_method'=>[
                'required','string',
                Rule::exists('payment_methods','code')->where(fn($q)=>$q
                    ->where('is_active',true)->whereIn('kind',self::PAYMENT_KINDS)),
            ],
            'payments.*.amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'payments.*.integration_type'=>['nullable',Rule::in(['1','2'])],
            'payments.*.card_brand'=>['nullable',Rule::in(['01','02','03','04','05','06','07','08','09','99'])],
            'payments.*.authorization_code'=>['nullable','string','max:128'],
        ]);

        $consumerDocument=preg_replace('/\D/','',(string)($data['consumer_document'] ?? ''));
        if ($consumerDocument!=='' && !in_array(strlen($consumerDocument),[11,14],true)) {
            throw ValidationException::withMessages([
                'consumer_document'=>'Informe um CPF ou CNPJ válido.',
            ]);
        }
        $customer=isset($data['customer_id'])
            ? Customer::query()->find($data['customer_id']) : null;

        if ($customer && $consumerDocument==='') {
            $consumerDocument=preg_replace('/\D/','',(string)$customer->document);
        }
        if ($consumerDocument!=='' && !in_array(strlen($consumerDocument),[11,14],true)) {
            throw ValidationException::withMessages([
                'consumer_document'=>'O cliente selecionado não possui CPF ou CNPJ válido.',
            ]);
        }

        // Do not trust names/prices/codes as authoritative catalog identity.
        // SalesService always loads catalog products and commits stock/finance
        // exactly once as a completed sale.
        $rows=[];
        foreach ($data['items'] as $item) {
            $rows[]=[
                'item_type'=>'product',
                'product_id'=>(int)$item['product_id'],
                'quantity'=>$item['quantity'],
                'unit_price'=>$item['unit_price'],
                'discount'=>$item['discount'] ?? 0,
            ];
        }
        $payments=[];
        foreach ($data['payments'] as $payment) {
            $payments[]=[
                'payment_method'=>$payment['payment_method'],
                'amount'=>$payment['amount'],
                'due_date'=>now()->toDateString(),
                'receivable'=>false,
                'integration_type'=>$payment['integration_type'] ?? null,
                'card_brand'=>$payment['card_brand'] ?? null,
                'authorization_code'=>$payment['authorization_code'] ?? null,
            ];
        }

        $sale=$salesService->create([
            'operation_type'=>'sale',
            'source'=>'nfce',
            'operation_date'=>now()->toDateString(),
            'final_consumer'=>true,
            'customer_id'=>$customer?->id,
            'consumer_document'=>$consumerDocument ?: null,
            'consumer_name'=>$consumerDocument ? ($data['consumer_name'] ?? $customer?->name) : null,
            'notes'=>$data['notes'] ?? null,
            'change_amount'=>0,
            'items'=>$rows,
            'payments'=>$payments,
        ],(int)$request->user()->id);

        // SalesService already attempted preparation after commit. If that
        // failed, recover only this fiscal document: never create another sale.
        try {
            $job=$fiscalPreparation->prepareNfceForSale($sale);
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::error('Venda de NFC-e concluída sem preparação fiscal',[
                'sale_id'=>$sale->id,'message'=>$error->getMessage(),
            ]);
            return redirect()->route('sales.show',$sale)
                ->with('error','Venda salva. Não foi possível preparar a NFC-e; verifique os dados fiscais da empresa.');
        }

        return $this->finish($job,$base['submit_mode'],$preflight);
    }

    private function finish(FiscalDocumentJob $job, string $mode, NFCePreflightService $preflight)
    {
        if ($mode!=='issue') {
            return redirect()->route('fiscal.show',$job)
                ->with('success','NFC-e preparada. Confira e emita quando desejar.');
        }

        $problems=$preflight->validate($job);
        if ($problems) {
            return redirect()->route('fiscal.show',$job)
                ->with('error','NFC-e salva. '.implode(' | ',$problems));
        }

        $queue=(string)config('queue.default','sync');
        if (in_array($queue,['','sync','null'],true)) {
            return redirect()->route('fiscal.show',$job)
                ->with('error','NFC-e salva. Configure a fila fiscal para autorizar a nota.');
        }

        try {
            ProcessNFCeJob::dispatch($job->id)->onConnection($queue);
            return redirect()->route('fiscal.show',$job)
                ->with('success','NFC-e enviada para emissão. Acompanhe a autorização nesta tela.');
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::error('Falha ao enfileirar NFC-e manual',[
                'fiscal_document_job_id'=>$job->id,'message'=>$error->getMessage(),
            ]);
            return redirect()->route('fiscal.show',$job)
                ->with('error','NFC-e salva. Não foi possível iniciar a emissão; tente novamente no documento.');
        }
    }
}
