<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReturnService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function create(array $data, int $userId): SaleReturn
    {
        return DB::transaction(function () use ($data,$userId) {
            $sale=Sale::query()->lockForUpdate()->findOrFail((int)$data['sale_id']);

            if($sale->operation_type!=='sale') {
                throw ValidationException::withMessages(['sale_id'=>'Somente vendas concluídas podem gerar devolução.']);
            }

            if($sale->status!=='completed') {
                throw ValidationException::withMessages(['sale_id'=>'A venda está cancelada e não pode receber devolução.']);
            }

            $requested=collect($data['items'] ?? [])
                ->map(fn($row)=>[
                    'sale_item_id'=>(int)($row['sale_item_id'] ?? 0),
                    'quantity'=>(string)($row['quantity'] ?? '0'),
                    'reason'=>trim((string)($row['reason'] ?? '')) ?: null,
                ])
                ->filter(fn($row)=>$row['sale_item_id']>0 && InventoryService::toMills($row['quantity'])>0)
                ->values();

            if($requested->isEmpty()) {
                throw ValidationException::withMessages(['items'=>'Selecione pelo menos um item e informe a quantidade a devolver.']);
            }

            $ids=$requested->pluck('sale_item_id')->unique()->values()->all();
            $saleItems=$sale->items()->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if($saleItems->count()!==count($ids)) {
                throw ValidationException::withMessages(['items'=>'Há item selecionado que não pertence à venda informada.']);
            }

            $alreadyReturned=SaleReturnItem::query()
                ->join('sale_returns','sale_returns.id','=','sale_return_items.sale_return_id')
                ->whereIn('sale_return_items.sale_item_id',$ids)
                ->where('sale_returns.status','completed')
                ->groupBy('sale_return_items.sale_item_id')
                ->selectRaw('sale_return_items.sale_item_id, COALESCE(SUM(sale_return_items.quantity),0) returned_quantity')
                ->pluck('returned_quantity','sale_item_id');

            $productIds=$saleItems->pluck('product_id')->filter()->unique()->values()->all();
            $products=Product::query()->whereIn('id',$productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $prepared=[];
            $totalCents=0;

            foreach($requested as $index=>$row) {
                $item=$saleItems->get($row['sale_item_id']);
                $requestedMills=InventoryService::toMills($row['quantity']);
                $soldMills=InventoryService::toMills($item->quantity);
                $returnedMills=InventoryService::toMills($alreadyReturned->get($item->id,'0'));
                $availableMills=max(0,$soldMills-$returnedMills);

                if($requestedMills>$availableMills) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity"=>'Quantidade maior que o saldo disponível para devolução de '.$item->product_name.'.',
                    ]);
                }

                $originalLineCents=(int)round((float)$item->line_total*100);
                $lineCents=$soldMills>0 ? (int)round($originalLineCents*$requestedMills/$soldMills) : 0;
                $totalCents+=$lineCents;

                $prepared[]=[
                    'item'=>$item,
                    'product'=>$item->product_id ? $products->get($item->product_id) : null,
                    'mills'=>$requestedMills,
                    'line_cents'=>$lineCents,
                    'reason'=>$row['reason'],
                ];
            }

            $return=SaleReturn::query()->create([
                'sale_id'=>$sale->id,
                'user_id'=>$userId,
                'return_date'=>$data['return_date'],
                'status'=>'completed',
                'total'=>InventoryService::moneyFromCents($totalCents),
                'notes'=>$data['notes'] ?? null,
                'completed_at'=>now(),
            ]);

            foreach($prepared as $row) {
                $item=$row['item'];
                $product=$row['product'];

                $return->items()->create([
                    'sale_item_id'=>$item->id,
                    'product_id'=>$item->product_id,
                    'service_id'=>$item->service_id,
                    'item_type'=>$item->item_type,
                    'item_name'=>$item->product_name,
                    'item_code'=>$item->product_sku,
                    'quantity'=>InventoryService::formatMills($row['mills']),
                    'unit_price'=>$item->unit_price,
                    'line_total'=>InventoryService::moneyFromCents($row['line_cents']),
                    'reason'=>$row['reason'],
                ]);

                if($item->item_type==='product' && $product?->control_stock) {
                    $this->inventory->applyLocked(
                        $product,
                        $row['mills'],
                        'sale_return',
                        'Devolução #'.$return->id.' da venda #'.$sale->id,
                        $userId,
                        $sale->id
                    );
                }
            }

            return $return->refresh();
        },3);
    }

    public function cancel(SaleReturn $saleReturn, int $userId): void
    {
        DB::transaction(function () use ($saleReturn,$userId) {
            $locked=SaleReturn::query()->lockForUpdate()->findOrFail($saleReturn->id);

            if($locked->status!=='completed') {
                throw ValidationException::withMessages(['return'=>'Esta devolução já está cancelada.']);
            }

            $items=$locked->items()->where('item_type','product')->whereNotNull('product_id')->orderBy('product_id')->get();
            $products=Product::query()->whereIn('id',$items->pluck('product_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach($items as $item) {
                $product=$products->get($item->product_id);
                if(!$product || !$product->control_stock) continue;

                $this->inventory->applyLocked(
                    $product,
                    -InventoryService::toMills($item->quantity),
                    'sale_return_cancel',
                    'Cancelamento da devolução #'.$locked->id.' da venda #'.$locked->sale_id,
                    $userId,
                    $locked->sale_id
                );
            }

            $locked->update(['status'=>'cancelled','cancelled_at'=>now()]);
        },3);
    }
}
