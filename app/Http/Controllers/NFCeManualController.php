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
use App\Services\Fiscal\NFCeManualTaxPreviewService;
use App\Services\FiscalPreparationService;
use App\Services\SalesService;
use App\Support\BrazilianTaxId;
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
            ->orderBy('sort_order')->orderBy('name')
            ->get(['code','name','kind'])
            ->filter(fn(PaymentMethod $method)=>in_array($method->fiscalKind(),self::PAYMENT_KINDS,true))
            ->map(fn(PaymentMethod $method)=>[
                'code'=>$method->code,'name'=>$method->name,'kind'=>$method->fiscalKind()
            ])->values();

        return view('fiscal.nfce.create',[
            'company'=>CompanySetting::current(),
            'settings'=>app(FiscalDocumentSettings::class),
            'products'=>Product::query()->where('is_active',true)
                ->orderBy('name')->get(['id','name','sku','ean_gtin','sale_price','unit','stock_quantity','control_stock','ncm','cest','origin','fiscal_tax_group_id']),
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
        NFCeManualTaxPreviewService $taxPreview,
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
                Rule::exists('payment_methods','code')->where(fn($q)=>$q->where('is_active',true)),
            ],
            'payments.*.amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'payments.*.integration_type'=>['nullable',Rule::in(['1','2'])],
            'payments.*.card_brand'=>['nullable',Rule::in(\App\Support\NFCeCardBrands::codes())],
            'payments.*.authorization_code'=>['nullable','string','max:128'],
            'payments.*.transaction_document'=>['nullable','string','max:18'],
            'payments.*.transaction_state'=>['nullable','string','size:2'],
            'payments.*.institution_document'=>['nullable','string','max:18'],
            'payments.*.beneficiary_document'=>['nullable','string','max:18'],
            'payments.*.terminal_id'=>['nullable','string','max:40'],
        ]);

        // Antes de salvar a venda (estoque/financeiro), conferir os dados
        // necessários ao XML. Nunca gerar outra venda para corrigir um cartão.
        $methodKinds=PaymentMethod::query()
            ->whereIn('code',array_column($data['payments'],'payment_method'))
            ->pluck('kind','code');
        $paymentErrors=[];
        foreach ($data['payments'] as $index=>$payment) {
            $prefix='payments.'.$index.'.';
            $kind=PaymentMethod::resolveFiscalKind(
                (string)($methodKinds[$payment['payment_method']] ?? ''),
                (string)$payment['payment_method']
            );
            if(!in_array($kind,self::PAYMENT_KINDS,true)){
                $paymentErrors[$prefix.'payment_method']='Forma de pagamento sem classificação fiscal para NFC-e. Configure crédito ou débito separadamente.';
                continue;
            }
            $card=in_array($kind,['credit_card','debit_card'],true);
            $electronic=$card || $kind==='pix';
            $integration=(string)($payment['integration_type'] ?? '');
            if ($card && !in_array($integration,['1','2'],true)) {
                $paymentErrors[$prefix.'integration_type']='Informe se o cartão foi recebido por TEF integrado ou máquina não integrada.';
            }
            if ($kind==='pix' && $integration!=='' && !in_array($integration,['1','2'],true)) {
                $paymentErrors[$prefix.'integration_type']='Escolha um tipo válido de integração do PIX.';
            }

            $transactionRaw=trim((string)($payment['transaction_document'] ?? ''));
            $transaction=preg_replace('/\\D/','',$transactionRaw);
            $state=strtoupper(trim((string)($payment['transaction_state'] ?? '')));
            if (($transaction==='') !== ($state==='')) {
                $paymentErrors[$prefix.'transaction_document']='O CNPJ e a UF da transação devem ser informados juntos.';
            }
            if ($transaction!=='' && (strlen($transaction)!==14 || !BrazilianTaxId::valid($transactionRaw))) {
                $paymentErrors[$prefix.'transaction_document']='Informe um CNPJ válido para o local da transação.';
            }
            if ($state!=='' && !in_array($state,[
                'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG',
                'PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO',
            ],true)) {
                $paymentErrors[$prefix.'transaction_state']='A UF da transação é inválida.';
            }
            $acquirerRaw=trim((string)($payment['institution_document'] ?? ''));
            $acquirer=preg_replace('/\\D/','',$acquirerRaw);
            $beneficiaryRaw=trim((string)($payment['beneficiary_document'] ?? ''));
            $beneficiary=preg_replace('/\\D/','',$beneficiaryRaw);
            if ($acquirer!=='' && (strlen($acquirer)!==14 || !BrazilianTaxId::valid($acquirerRaw))) {
                $paymentErrors[$prefix.'institution_document']='Informe um CNPJ válido para a adquirente.';
            }
            if ($beneficiary!=='' && (strlen($beneficiary)!==14 || !BrazilianTaxId::valid($beneficiaryRaw))) {
                $paymentErrors[$prefix.'beneficiary_document']='Informe um CNPJ válido para o beneficiário.';
            }
            if ($card && $integration==='1' && ($acquirer==='' || trim((string)($payment['authorization_code'] ?? ''))==='')) {
                $paymentErrors[$prefix.'institution_document']='Cartão integrado exige CNPJ da adquirente e código de autorização.';
            }
            if (!$electronic && (
                $integration!=='' || $acquirer!=='' || $transaction!=='' || $state!==''
                || $beneficiary!=='' || trim((string)($payment['authorization_code'] ?? ''))!==''
                || trim((string)($payment['terminal_id'] ?? ''))!=='' || trim((string)($payment['card_brand'] ?? ''))!==''
            )) {
                $paymentErrors[$prefix.'payment_method']='Informações de cartão/PIX não devem acompanhar outra forma de pagamento.';
            }
        }
        if ($paymentErrors) {
            throw ValidationException::withMessages($paymentErrors);
        }

        $consumerRaw=trim((string)($data['consumer_document'] ?? ''));
        $consumerDocument=preg_replace('/\D/','',$consumerRaw);
        if ($consumerRaw!=='' && !BrazilianTaxId::valid($consumerRaw)) {
            throw ValidationException::withMessages([
                'consumer_document'=>'Informe um CPF ou CNPJ válido.',
            ]);
        }
        $customer=isset($data['customer_id'])
            ? Customer::query()->find($data['customer_id']) : null;

        $customerRaw='';
        if ($customer && $consumerDocument==='') {
            $customerRaw=trim((string)$customer->document);
            $consumerDocument=preg_replace('/\D/','',$customerRaw);
        }
        if ($customerRaw!=='' && !BrazilianTaxId::valid($customerRaw)) {
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
        // Erros fiscais antes da baixa de estoque e de qualquer lançamento
        // financeiro. A conferência será repetida sobre a NFC-e preparada.
        if ($base['submit_mode']==='issue') {
            $taxPreview->verify($rows);
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
                'transaction_document'=>preg_replace('/\\D/','',(string)($payment['transaction_document'] ?? '')) ?: null,
                'transaction_state'=>strtoupper(trim((string)($payment['transaction_state'] ?? ''))) ?: null,
                'institution_document'=>preg_replace('/\\D/','',(string)($payment['institution_document'] ?? '')) ?: null,
                'beneficiary_document'=>preg_replace('/\\D/','',(string)($payment['beneficiary_document'] ?? '')) ?: null,
                'terminal_id'=>$payment['terminal_id'] ?? null,
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
                ->with('success','NFC-e salva. Pronta para emitir.');
        }

        $problems=$preflight->validate($job);
        if ($problems) {
            return redirect()->route('fiscal.show',$job)
                ->with('error','NFC-e salva. '.implode(' | ',$problems));
        }

        // A requisição de emissão nunca deve executar ACBr/FFI com queue=sync.
        // O worker fiscal processa a fila database separada do fechamento da venda.
        try {
            ProcessNFCeJob::dispatch($job->id)->onConnection('database')->onQueue('fiscal');
            return redirect()->route('fiscal.show',$job)
                ->with('success',$job->emission_mode==='offline'
                    ? 'NFC-e na fila de preparação offline: assinatura do XML e geração do DANFE, sem envio à SEFAZ.'
                    : 'Emissão iniciada. Acompanhe a situação da nota.');
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::error('Falha ao enfileirar NFC-e manual',[
                'fiscal_document_job_id'=>$job->id,'message'=>$error->getMessage(),
            ]);
            return redirect()->route('fiscal.show',$job)
                ->with('error','NFC-e salva. Não foi possível iniciar a emissão; tente novamente no documento.');
        }
    }
}
