<?php
namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function create(array $data, int $userId): Sale
    {
        return DB::transaction(function () use ($data, $userId) {
            $rows=collect($data['items']);
            $operationType=$data['operation_type'] ?? 'sale';

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

                    if($operationType==='sale' && $product->control_stock &&
                        InventoryService::toMills($product->stock_quantity)<$mills) {
                        throw ValidationException::withMessages([
                            "items.$index.quantity"=>'Estoque insuficiente para '.$product->name.'.'
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
                'user_id'=>$userId,
                'operation_type'=>$operationType,
                'source'=>$data['source'] ?? 'manual',
                'operation_date'=>$data['operation_date'] ?? now()->toDateString(),
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
                $payments=collect([[
                    'amount'=>InventoryService::moneyFromCents($totalCents),
                    'due_date'=>$data['operation_date'] ?? now()->toDateString(),
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
                        'receivable'=>(bool)($payment['receivable'] ?? true),
                    ]);
                });
            }

            return $sale->refresh();
        },3);
    }

    public function cancel(Sale $sale, int $userId): void
    {
        DB::transaction(function () use ($sale, $userId) {
            $locked=Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if($locked->status!=='completed') {
                throw ValidationException::withMessages([
                    'sale'=>'A operação já foi cancelada.'
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

            $locked->update([
                'status'=>'cancelled',
                'cancelled_at'=>now(),
            ]);
        },3);
    }
}
