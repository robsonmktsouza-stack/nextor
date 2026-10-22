<?php
namespace App\Services;

use App\Models\AppSetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly FinancialService $financial,
        private readonly FiscalPreparationService $fiscalPreparation,
        private readonly WebhookService $webhooks,
    ) {}

    public function create(array $data, int $userId): Sale
    {
        $sale=DB::transaction(function () use ($data, $userId) {
            $rows=collect($data['items']);
            // Lock the active shift in the SAME sale transaction. A concurrent
            // close must not detach a paid sale from its cash reconciliation.
            $cashSession=($data['source'] ?? 'manual')==='pdv'
                ? app(PdvCashService::class)->forSale($userId)
                : null;
            $operationType=$data['operation_type'] ?? 'sale';
            $sourceQuote=null;
            if(!empty($data['source_quote_id'])) {
                if($operationType!=='sale') {
                    throw ValidationException::withMessages(['sale'=>'A conversão precisa resultar em uma venda.']);
                }
                $sourceQuote=Sale::query()->lockForUpdate()->findOrFail($data['source_quote_id']);
                if($sourceQuote->operation_type!=='quote' || $sourceQuote->status!=='completed'
                   || ($sourceQuote->quote_expires_at && $sourceQuote->quote_expires_at->lt(today()))) {
                    throw ValidationException::withMessages([
                        'sale'=>'Este orçamento está cancelado, expirado ou já foi convertido.'
                    ]);
                }
                if(Sale::query()->where('source_quote_id',$sourceQuote->id)->exists()) {
                    throw ValidationException::withMessages(['sale'=>'Este orçamento já foi convertido em venda.']);
                }
            }
            $allowNegativeStock=(bool)AppSetting::value(
                'inventory',
                'allow_negative_stock',
                AppSetting::value('operations','allow_negative_stock',false)
            );

            $productIds=$rows
                ->where('item_type','product')
                ->pluck('product_id')
                ->filter()
                ->map(fn($id)=>(int)$id)
                ->unique()
                ->values()
                ->all();

            $serviceIds=$rows
                ->where('item_type','service')
                ->pluck('service_id')
                ->filter()
                ->map(fn($id)=>(int)$id)
                ->unique()
                ->values()
                ->all();

            $products=Product::query()
                ->whereIn('id',$productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $services=Service::query()
                ->whereIn('id',$serviceIds)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $items=[];
            $subtotalCents=0;
            $discountCents=0;
            $totalCents=0;

            foreach($rows as $index=>$row) {
                $type=$row['item_type'];
                $mills=InventoryService::toMills($row['quantity']);

                if($mills<1) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity"=>'Informe uma quantidade válida.'
                    ]);
                }

                $product=null;
                $service=null;
                $name='';
                $code='';

                if($type==='product') {
                    $product=$products->get((int)($row['product_id'] ?? 0));

                    if(!$product || !$product->is_active) {
                        throw ValidationException::withMessages([
                            "items.$index.product_id"=>'Produto inválido ou inativo.'
                        ]);
                    }

                    if($operationType==='sale' && !$allowNegativeStock && $product->control_stock &&
                        InventoryService::toMills($product->stock_quantity)<$mills) {
                        throw ValidationException::withMessages([
                            'items'=>'Estoque insuficiente para '.$product->name.'.'
                        ]);
                    }

                    $name=$product->name;
                    $code=$product->sku;
                } elseif($type==='service') {
                    $service=$services->get((int)($row['service_id'] ?? 0));

                    if(!$service || !$service->is_active) {
                        throw ValidationException::withMessages([
                            "items.$index.service_id"=>'Serviço inválido ou inativo.'
                        ]);
                    }

                    $name=$service->name;
                    $code=$service->service_list_item ?: ('SERV-'.$service->id);
                } else {
                    $name=trim((string)($row['description'] ?? ''));
                    if($name==='') {
                        $name=$type==='freight' ? 'Frete' : 'Outras despesas';
                    }
                    $code=$type==='freight' ? 'FRETE' : 'DESPESA';
                }

                $unitCents=(int)round((float)$row['unit_price']*100);
                $lineGross=(int)round($unitCents*$mills/1000);
                $lineDiscount=(int)round((float)($row['discount'] ?? 0)*100);

                if($lineDiscount>$lineGross) {
                    throw ValidationException::withMessages([
                        "items.$index.discount"=>'O desconto não pode ser maior que o valor do item.'
                    ]);
                }

                $lineCents=$lineGross-$lineDiscount;

                $subtotalCents+=$lineGross;
                $discountCents+=$lineDiscount;
                $totalCents+=$lineCents;

                $items[]=[
                    'type'=>$type,
                    'product'=>$product,
                    'service'=>$service,
                    'mills'=>$mills,
                    'unitCents'=>$unitCents,
                    'discountCents'=>$lineDiscount,
                    'lineCents'=>$lineCents,
                    'name'=>$name,
                    'code'=>$code,
                    'notes'=>$row['notes'] ?? null,
                ];
            }

            if(!$items) {
                throw ValidationException::withMessages(['items'=>'Adicione pelo menos um item.']);
            }

            $sale=Sale::create([
                'customer_id'=>$data['customer_id'] ?? null,
                'consumer_document'=>$data['consumer_document'] ?? null,
                'consumer_name'=>$data['consumer_name'] ?? null,
                'cash_received'=>$data['cash_received'] ?? null,
                'change_amount'=>$data['change_amount'] ?? 0,
                'user_id'=>$userId,
                'operation_type'=>$operationType,
                'source'=>$data['source'] ?? 'manual',
                'pdv_cash_session_id'=>$cashSession?->id,
                'pdv_checkout_key'=>$data['pdv_checkout_key'] ?? null,
                'pdv_checkout_hash'=>$data['pdv_checkout_hash'] ?? null,
                'source_quote_id'=>$sourceQuote?->id,
                'operation_date'=>$data['operation_date'] ?? now()->toDateString(),
                'quote_expires_at'=>$operationType==='quote'
                    ? ($data['quote_expires_at'] ?? \Carbon\Carbon::parse($data['operation_date'] ?? now()->toDateString())
                        ->addDays(max(0,(int)AppSetting::value('operations','quote_valid_days',15)))
                        ->toDateString())
                    : null,
                'final_consumer'=>(bool)($data['final_consumer'] ?? true),
                'keyword'=>$data['keyword'] ?? null,
                'status'=>'completed',
                'subtotal'=>InventoryService::moneyFromCents($subtotalCents),
                'discount_total'=>InventoryService::moneyFromCents($discountCents),
                'total'=>InventoryService::moneyFromCents($totalCents),
                'notes'=>$data['notes'] ?? null,
                'completed_at'=>now(),
            ]);

            foreach($items as $entry) {
                $product=$entry['product'];
                $service=$entry['service'];

                $sale->items()->create([
                    'item_type'=>$entry['type'],
                    'product_id'=>$product?->id,
                    'service_id'=>$service?->id,
                    'product_name'=>$entry['name'],
                    'product_sku'=>$entry['code'],
                    'quantity'=>InventoryService::formatMills($entry['mills']),
                    'unit_price'=>InventoryService::moneyFromCents($entry['unitCents']),
                    'discount'=>InventoryService::moneyFromCents($entry['discountCents']),
                    'line_total'=>InventoryService::moneyFromCents($entry['lineCents']),
                    'notes'=>$entry['notes'],
                ]);

                if($operationType==='sale' && $entry['type']==='product' && $product?->control_stock) {
                    $this->inventory->applyLocked(
                        $product,
                        -$entry['mills'],
                        'sale',
                        'Venda #'.$sale->id,
                        $userId,
                        $sale->id
                    );
                }
            }

            $payments=collect($data['payments'] ?? [])
                ->filter(fn($payment)=>(float)($payment['amount'] ?? 0)>0)
                ->values();

            if($payments->isEmpty() && $operationType==='sale' && $totalCents>0) {
                $baseDate=\Carbon\Carbon::parse($data['operation_date'] ?? now()->toDateString());
                $defaultDueDays=(int)AppSetting::value('operations','default_due_days',0);
                $payments=collect([[
                    'amount'=>InventoryService::moneyFromCents($totalCents),
                    'due_date'=>$baseDate->copy()->addDays(max(0,$defaultDueDays))->toDateString(),
                    'payment_method'=>null,
                    'receivable'=>true,
                ]]);
            }

            if($payments->isNotEmpty()) {
                $paymentCents=$payments->sum(fn($payment)=>(int)round((float)$payment['amount']*100));

                if(abs($paymentCents-$totalCents)>1) {
                    throw ValidationException::withMessages([
                        'payments'=>'O total das parcelas deve ser igual ao total da operação.'
                    ]);
                }

                $payments->each(function($payment,$index) use ($sale) {
                    $sale->payments()->create([
                        'installment'=>$index+1,
                        'amount'=>$payment['amount'],
                        'due_date'=>$payment['due_date'] ?? null,
                        'payment_method'=>$payment['payment_method'] ?? null,
                        'integration_type'=>$payment['integration_type'] ?? null,
                        'transaction_document'=>$payment['transaction_document'] ?? null,
                        'transaction_state'=>$payment['transaction_state'] ?? null,
                        'institution_document'=>$payment['institution_document'] ?? null,
                        'card_brand'=>$payment['card_brand'] ?? null,
                        'authorization_code'=>$payment['authorization_code'] ?? null,
                        'beneficiary_document'=>$payment['beneficiary_document'] ?? null,
                        'terminal_id'=>$payment['terminal_id'] ?? null,
                        'receivable'=>(bool)($payment['receivable'] ?? true),
                    ]);
                });
            }

            if($operationType==='sale' && (bool)AppSetting::value('operations','auto_finance_sale',true)) {
                $this->financial->syncSale($sale,$userId);
            }

            if($sourceQuote) {
                $sourceQuote->update(['status'=>'converted']);
            }
            if($sale->source==='pdv' && !empty($data['pdv_discount_approved_by'])) {
                app(\App\Services\PdvActionAuthorization::class)->record(
                    'discount_approved',$userId,(int)$data['pdv_discount_approved_by'],
                    'Desconto acima do limite permitido ao operador.',$sale->id
                );
            }

            return $sale->refresh();
        },3);

        try {
            $this->fiscalPreparation->prepareForSale($sale);
        } catch (\Throwable $e) {
            // Venda, estoque e financeiro já foram confirmados. Evitar erro HTTP
            // que leve o caixa a registrar outra venda por engano.
            \Illuminate\Support\Facades\Log::error('Falha ao preparar documento fiscal', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        // A venda foi efetivada: o processamento fiscal usa SEMPRE a fila
        // database (worker fiscal separado), mesmo que a fila padrão seja sync.
        // Nunca executar ACBr/FFI na requisição nem criar outra venda ao falhar.
        if ($sale->source === 'pdv'
            && ((bool) AppSetting::value('pdv', 'auto_nfce', false)
                || (bool) AppSetting::value('nfce', 'auto_from_pdv', false))) {
            $fiscalJob = \App\Models\FiscalDocumentJob::query()
                ->where('sale_id', $sale->id)
                ->where('document_type', 'nfce')
                ->where('status', 'prepared')
                ->first();
            if ($fiscalJob) {
                try {
                    \App\Jobs\ProcessNFCeJob::dispatch($fiscalJob->id)->onConnection('database');
                } catch (\Throwable $e) {
                    // A venda já foi confirmada. Nunca retornar erro HTTP para
                    // não induzir o caixa a finalizar a mesma venda novamente.
                    \Illuminate\Support\Facades\Log::error('Falha ao enfileirar NFC-e', [
                        'fiscal_job_id' => $fiscalJob->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->webhooks->queue(
            $sale->operation_type==='quote' ? 'quote.created' : 'sale.completed',
            [
                'sale_id'=>$sale->id,
                'operation_type'=>$sale->operation_type,
                'source'=>$sale->source,
                'customer_id'=>$sale->customer_id,
                'total'=>(string)$sale->total,
                'status'=>$sale->status,
                'operation_date'=>optional($sale->operation_date)->toDateString(),
                'quote_expires_at'=>optional($sale->quote_expires_at)->toDateString(),
            ]
        );

        return $sale;
    }

    /**
     * Converts an existing quote into a new sale using the regular commercial
     * pipeline. Original item prices/discounts remain fixed, but current
     * inventory restrictions still apply. A unique source_quote_id and a
     * locked status transition prevent duplicate stock/financial effects.
     */
    public function convertQuote(Sale $quote, int $userId): Sale
    {
        if($quote->operation_type!=='quote') {
            throw ValidationException::withMessages(['sale'=>'Somente orçamentos podem ser convertidos.']);
        }
        $quote->loadMissing('items');
        return $this->create([
            'source_quote_id'=>$quote->id,
            'operation_type'=>'sale',
            'source'=>'manual',
            'operation_date'=>now()->toDateString(),
            'customer_id'=>$quote->customer_id,
            'consumer_document'=>$quote->consumer_document,
            'consumer_name'=>$quote->consumer_name,
            'final_consumer'=>$quote->final_consumer,
            'keyword'=>$quote->keyword,
            'notes'=>$quote->notes,
            'items'=>$quote->items->map(fn($item)=>[
                'item_type'=>$item->item_type,
                'product_id'=>$item->product_id,
                'service_id'=>$item->service_id,
                'description'=>$item->product_name,
                'quantity'=>(string)$item->quantity,
                'unit_price'=>(string)$item->unit_price,
                'discount'=>(string)$item->discount,
                'notes'=>$item->notes,
            ])->all(),
        ],$userId);
    }

    /**
     * A venda só pode ser estornada se todos os documentos fiscais tiverem
     * resultado conhecido e não houver emissão ou evento em andamento.
     */
    public static function fiscalDocumentAllowsSaleCancellation(\App\Models\FiscalDocumentJob $document): bool
    {
        if (in_array($document->status,['prepared','rejected'],true)) {
            return !$document->authorized_at && !$document->protocol
                && !$document->cancellation_status;
        }
        if ($document->status!=='cancelled') return false;
        // Documento administrativo cancelado antes da autorização.
        if (!$document->authorized_at && !$document->protocol) {
            return !$document->cancellation_status;
        }
        // Nota previamente autorizada: exige confirmação da SEFAZ.
        return $document->cancellation_status==='authorized'
            && $document->cancelled_at!==null;
    }

    public function cancel(Sale $sale, int $userId, ?string $pdvReason=null, ?int $approvedBy=null): void
    {
        DB::transaction(function () use ($sale, $userId, $pdvReason, $approvedBy) {
            $locked=Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if($locked->status!=='completed') {
                throw ValidationException::withMessages([
                    'sale'=>'A operação já foi cancelada.'
                ]);
            }

            $fiscalDocuments=\App\Models\FiscalDocumentJob::query()
                ->where('sale_id',$locked->id)
                ->lockForUpdate()
                ->get();
            if($fiscalDocuments->contains(
                fn($document)=>!self::fiscalDocumentAllowsSaleCancellation($document)
            )) {
                throw ValidationException::withMessages([
                    'sale'=>'Esta venda possui documento fiscal pendente ou autorizado. Conclua a regularização da NFC-e antes de cancelar a venda.'
                ]);
            }

            if($locked->operation_type==='sale' && $locked->returns()->where('status','completed')->exists()) {
                throw ValidationException::withMessages([
                    'sale'=>'Esta venda possui devolução ativa. Cancele primeiro as devoluções vinculadas antes de cancelar a venda.'
                ]);
            }

            if($locked->operation_type==='sale') {
                $items=$locked->items()
                    ->where('item_type','product')
                    ->whereNotNull('product_id')
                    ->orderBy('product_id')
                    ->get();

                $products=Product::query()
                    ->whereIn('id',$items->pluck('product_id')->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach($items as $item) {
                    $product=$products->get($item->product_id);
                    if(!$product || !$product->control_stock) continue;

                    $this->inventory->applyLocked(
                        $product,
                        InventoryService::toMills($item->quantity),
                        'sale_cancel',
                        'Estorno da venda #'.$locked->id,
                        $userId,
                        $locked->id
                    );
                }
            }

            if($locked->operation_type==='sale') {
                $this->financial->cancelSale($locked);
            }

            $newNotes=$locked->notes;
            if($pdvReason!==null) {
                $pdvReason=trim(preg_replace('/\s+/u',' ',$pdvReason) ?? $pdvReason);
                $record='Cancelamento PDV em '.now()->format('d/m/Y H:i')
                    .' (operador #'.$userId.'): '.$pdvReason;
                $existing=trim((string)$newNotes);
                $newNotes=($existing!==''?$existing."\n":'').$record;
            }

            $locked->update([
                'status'=>'cancelled',
                'cancelled_at'=>now(),
                'notes'=>$newNotes,
            ]);
            if($pdvReason!==null && $approvedBy!==null) {
                app(\App\Services\PdvActionAuthorization::class)->record(
                    'sale_cancel',$userId,$approvedBy,$pdvReason,$locked->id
                );
            }
        },3);

        \App\Models\FiscalDocumentJob::query()
            ->where('sale_id',$sale->id)
            ->where('status','prepared')
            ->update([
                'status'=>'cancelled',
                'error_message'=>'Documento cancelado antes da transmissão porque a venda/orçamento foi cancelado.',
                'processed_at'=>now(),
                'updated_at'=>now(),
            ]);

        $this->webhooks->queue(
            $sale->operation_type==='quote' ? 'quote.cancelled' : 'sale.cancelled',
            [
                'sale_id'=>$sale->id,
                'operation_type'=>$sale->operation_type,
                'status'=>'cancelled',
            ]
        );
    }
}
