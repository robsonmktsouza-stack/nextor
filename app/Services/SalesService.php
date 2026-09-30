<?php
namespace App\Services;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function create(array $data, int $userId): Sale
    {
        return DB::transaction(function () use ($data, $userId) {
            $rows = collect($data['items']);
            $ids = $rows->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['items' => 'Um produto não pode aparecer duas vezes na mesma venda.']);
            }
            $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== count($ids)) {
                throw ValidationException::withMessages(['items' => 'Há um produto inválido na venda.']);
            }
            $items = [];
            $totalCents = 0;
            foreach ($rows as $row) {
                $product = $products[(int) $row['product_id']];
                $mills = InventoryService::toMills($row['quantity']);
                if ($mills < 1 || ! $product->is_active) {
                    throw ValidationException::withMessages(['items' => 'Produto desativado ou quantidade inválida: '.$product->name]);
                }
                if (InventoryService::toMills($product->stock_quantity) < $mills) {
                    throw ValidationException::withMessages(['items' => 'Estoque insuficiente para '.$product->name]);
                }
                // O preço é sempre obtido do servidor, nunca aceito do navegador.
                $unitCents = (int) round((float) $product->sale_price * 100);
                $lineCents = (int) round($unitCents * $mills / 1000);
                $totalCents += $lineCents;
                $items[] = compact('product','mills','unitCents','lineCents');
            }
            $sale = Sale::create([
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => $userId,'status' => 'completed',
                'total' => InventoryService::moneyFromCents($totalCents),
                'notes' => $data['notes'] ?? null,'completed_at' => now(),
            ]);
            foreach ($items as $entry) {
                $p = $entry['product'];
                $sale->items()->create([
                    'product_id' => $p->id,'product_name' => $p->name,'product_sku' => $p->sku,
                    'quantity' => InventoryService::formatMills($entry['mills']),
                    'unit_price' => InventoryService::moneyFromCents($entry['unitCents']),
                    'line_total' => InventoryService::moneyFromCents($entry['lineCents']),
                ]);
                $this->inventory->applyLocked($p, -$entry['mills'], 'sale', 'Venda #'.$sale->id, $userId, $sale->id);
            }
            return $sale;
        }, 3);
    }

    public function cancel(Sale $sale, int $userId): void
    {
        DB::transaction(function () use ($sale, $userId) {
            $locked = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            if ($locked->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'A venda já foi cancelada.']);
            }
            $items = $locked->items()->orderBy('product_id')->get();
            $products = Product::query()->whereIn('id', $items->pluck('product_id')->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $p = $products->get($item->product_id);
                if (! $p) {
                    throw ValidationException::withMessages(['sale' => 'Produto da venda não encontrado.']);
                }
                $this->inventory->applyLocked(
                    $p, InventoryService::toMills($item->quantity), 'sale_cancel',
                    'Estorno da venda #'.$locked->id, $userId, $locked->id
                );
            }
            $locked->update(['status' => 'cancelled','cancelled_at' => now()]);
        }, 3);
    }
}
