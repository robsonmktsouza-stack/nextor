<?php
namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    // Quantidades em milésimos, para evitar perdas por operações repetidas com float.
    public static function toMills(int|float|string $value): int
    {
        return (int) round((float) $value * 1000);
    }
    public static function formatMills(int $mills): string
    {
        return number_format($mills / 1000, 3, '.', '');
    }
    public static function moneyFromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
    public function adjust(int $productId, string $type, string $quantity, string $reason, int $userId): void
    {
        DB::transaction(function () use ($productId, $type, $quantity, $reason, $userId) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $value = self::toMills($quantity);
            $old = self::toMills($product->stock_quantity);
            if ($type === 'entry') $delta = $value;
            elseif ($type === 'exit') $delta = -$value;
            else $delta = $value - $old;
            if (in_array($type, ['entry','exit'], true) && $value <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Informe uma quantidade maior que zero.']);
            }
            if ($type === 'adjustment' && $delta === 0) {
                throw ValidationException::withMessages(['quantity' => 'O estoque informado já é o atual.']);
            }
            $this->applyLocked($product, $delta, $type, $reason, $userId);
        }, 3);
    }
    // Deve ser chamado dentro de transação, com o produto já bloqueado.
    public function applyLocked(Product $product, int $deltaMills, string $type, string $reason, ?int $userId, ?int $saleId = null): void
    {
        $old = self::toMills($product->stock_quantity);
        $new = $old + $deltaMills;
        if ($new < 0) {
            throw ValidationException::withMessages(['items' => 'Estoque insuficiente: '.$product->name.' (disponível: '.self::formatMills($old).' '.$product->unit.').']);
        }
        $product->stock_quantity = self::formatMills($new);
        $product->save();
        StockMovement::create([
            'product_id' => $product->id,'user_id' => $userId, 'sale_id' => $saleId,
            'type' => $type,'quantity_delta' => self::formatMills($deltaMills),
            'previous_quantity' => self::formatMills($old),
            'new_quantity' => self::formatMills($new),'reason' => $reason,
        ]);
    }
}
