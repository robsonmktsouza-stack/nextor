<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\IncomingNfe;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Transições de compras transacionais, com totais calculados no servidor. */
class PurchaseService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function saveDraft(array $data,int $userId,?Purchase $purchase=null): Purchase
    {
        return DB::transaction(function()use($data,$userId,$purchase) {
            if($purchase) {
                $purchase=Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
                $this->requireDraft($purchase);
            }
            $supplier=Customer::query()->findOrFail((int)$data['supplier_id']);
            if(!$supplier->is_supplier) {
                throw ValidationException::withMessages(['supplier_id'=>'Selecione um fornecedor cadastrado.']);
            }
            $items=$this->items($data['items']);
            $freight=$this->cents($data['freight']??'0');
            $discount=$this->cents($data['discount']??'0');
            $otherExpenses=$this->cents($data['other_expenses']??'0');
            $subtotal=array_sum(array_column($items,'line_cents'));
            $total=$subtotal+$freight+$otherExpenses-$discount;
            if($total<=0||$total>999999999999) {
                throw ValidationException::withMessages(['discount'=>'O total da compra deve ser maior que zero.']);
            }
            $invoiceId=$data['incoming_nfe_id']??null;
            if($invoiceId) {
                $invoice=IncomingNfe::query()->findOrFail((int)$invoiceId);
                if($invoice->status!=='xml_received'||!$invoice->xml_path) {
                    throw ValidationException::withMessages(['incoming_nfe_id'=>'A NF-e precisa ter XML completo.']);
                }
                $cnpj=fn($v)=>preg_replace('/\D/','',(string)$v);
                if($cnpj($supplier->document)!==$cnpj($invoice->supplier_cnpj)
                    || $cnpj($supplier->document)==='') {
                    throw ValidationException::withMessages(['incoming_nfe_id'=>'O CNPJ do fornecedor não coincide com o XML.']);
                }
                if($invoice->total===null || $this->cents($invoice->total)!==$total) {
                    throw ValidationException::withMessages(['incoming_nfe_id'=>'O total da compra deve conferir com a NF-e vinculada.']);
                }
                if(Purchase::query()->where('incoming_nfe_id',$invoiceId)
                    ->when($purchase,fn($q)=>$q->whereKeyNot($purchase->id))->exists()) {
                    throw ValidationException::withMessages(['incoming_nfe_id'=>'Essa NF-e já está vinculada a outra compra.']);
                }
            }
            $attributes=[
                'supplier_id'=>$supplier->id,
                'incoming_nfe_id'=>$invoiceId,
                'created_by'=>$purchase?->created_by ?: $userId,
                'status'=>'draft',
                'operation_date'=>$data['operation_date'],
                'due_date'=>$data['due_date']??null,
                'document_number'=>trim((string)($data['document_number']??''))?:null,
                'keyword'=>trim((string)($data['keyword']??''))?:null,
                'subtotal'=>$this->money($subtotal),
                'freight'=>$this->money($freight),
                'other_expenses'=>$this->money($otherExpenses),
                'discount'=>$this->money($discount),
                'total'=>$this->money($total),
                'generate_payable'=>(bool)($data['generate_payable']??false),
                'notes'=>$data['notes']??null,
            ];
            if($attributes['generate_payable']&&!$attributes['due_date']) {
                throw ValidationException::withMessages(['due_date'=>'Informe o vencimento da conta a pagar.']);
            }
            if(!$purchase) $purchase=Purchase::query()->create($attributes);
            else { $purchase->update($attributes); $purchase->items()->delete(); }
            foreach($items as $item) {
                $purchase->items()->create([
                    'product_id'=>$item['product']->id,'product_name'=>$item['product']->name,
                    'product_sku'=>$item['product']->sku,'unit'=>$item['product']->unit,
                    'quantity'=>InventoryService::formatMills($item['mills']),
                    'unit_cost'=>$this->money($item['unit_cents']),
                    'line_total'=>$this->money($item['line_cents']),
                    'discount'=>$this->money($item['discount_cents']),
                    'other_costs'=>$this->money($item['other_costs_cents']),
                    'external_costs'=>$this->money($item['external_costs_cents']),
                    'ipi'=>$this->money($item['ipi_cents']),
                    'icms_st'=>$this->money($item['icms_st_cents']),
                ]);
            }
            return $purchase->fresh(['items','supplier','invoice']);
        },3);
    }

    public function receive(Purchase $purchase,int $userId): Purchase
    {
        return DB::transaction(function()use($purchase,$userId) {
            $purchase=Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->requireDraft($purchase);
            $items=$purchase->items()->orderBy('product_id')->get();
            if($items->isEmpty()) {
                throw ValidationException::withMessages(['items'=>'Inclua pelo menos um produto.']);
            }
            if($purchase->generate_payable && !$purchase->due_date) {
                throw ValidationException::withMessages(['due_date'=>'Informe o vencimento antes de receber.']);
            }
            foreach($items as $item) {
                $product=Product::query()->lockForUpdate()->findOrFail($item->product_id);
                if(!$product->is_active) {
                    throw ValidationException::withMessages(['items'=>'Produto inativo: '.$product->name]);
                }
                if($product->control_stock) {
                    $this->inventory->applyLocked($product,InventoryService::toMills($item->quantity),
                        'purchase','Compra #'.$purchase->id,$userId,null,$purchase->id);
                }
                // custo deve refletir a última entrada confirmada; não altera preço de venda
                $product->cost_price=$item->unit_cost;
                $product->save();
            }
            if($purchase->generate_payable) {
                $category=FinancialCategory::query()
                    ->where('type','expense')->where('name','Fornecedores')->first();
                $purchase->payable()->create([
                    'type'=>'payable','status'=>'open','category_id'=>$category?->id,
                    'customer_id'=>$purchase->supplier_id,'created_by'=>$userId,
                    'description'=>'Compra #'.str_pad((string)$purchase->id,5,'0',STR_PAD_LEFT).
                        ' — '.$purchase->supplier->name,
                    'document_number'=>$purchase->document_number ?: 'COMPRA-'.$purchase->id,
                    'issue_date'=>$purchase->operation_date->toDateString(),
                    'competence_date'=>$purchase->operation_date->toDateString(),
                    'due_date'=>$purchase->due_date->toDateString(),
                    'amount'=>$purchase->total,'paid_amount'=>'0.00',
                    'notes'=>'Gerado no recebimento da compra. Controle pelo Financeiro.',
                ]);
            }
            $purchase->update(['status'=>'received','received_at'=>now()]);
            return $purchase->fresh(['items','payable','supplier']);
        },3);
    }

    public function cancel(Purchase $purchase,int $userId): Purchase
    {
        return DB::transaction(function()use($purchase,$userId) {
            $purchase=Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if($purchase->status==='cancelled') {
                throw ValidationException::withMessages(['purchase'=>'Compra já cancelada.']);
            }
            if($purchase->status==='received') {
                $payable=FinancialEntry::query()->where('purchase_id',$purchase->id)
                    ->lockForUpdate()->first();
                if($payable && ($payable->activeSettlements()->exists()
                    || (float)$payable->paid_amount>0)) {
                    throw ValidationException::withMessages(['purchase'=>
                        'Há pagamentos registrados. Estorne todas as baixas financeiras antes de cancelar.']);
                }
                foreach($purchase->items()->orderBy('product_id')->get() as $item) {
                    // Estornar exatamente a quantidade lançada, mesmo se a configuração de controle mudou.
                    $movements=StockMovement::query()->where('purchase_id',$purchase->id)
                        ->where('product_id',$item->product_id)->where('type','purchase')->get();
                    foreach($movements as $movement) {
                        $product=Product::query()->lockForUpdate()->findOrFail($item->product_id);
                        $this->inventory->applyLocked($product,-InventoryService::toMills($movement->quantity_delta),
                            'purchase_cancel','Cancelamento da compra #'.$purchase->id,$userId,null,$purchase->id);
                    }
                }
                if($payable) $payable->update([
                    'status'=>'cancelled','paid_amount'=>'0.00','cancelled_at'=>now(),
                ]);
            }
            $purchase->update(['status'=>'cancelled','cancelled_at'=>now()]);
            return $purchase;
        },3);
    }

    private function items(array $items): array
    {
        if(count($items)<1||count($items)>80) {
            throw ValidationException::withMessages(['items'=>'Informe de 1 a 80 itens.']);
        }
        $ids=array_map(fn($i)=>(int)$i['product_id'],$items);
        if(count($ids)!==count(array_unique($ids))) {
            throw ValidationException::withMessages(['items'=>'Não repita um produto: ajuste a quantidade na mesma linha.']);
        }
        $products=Product::query()->whereIn('id',$ids)->get()->keyBy('id');
        $result=[];
        foreach($items as $item) {
            $product=$products->get((int)$item['product_id']);
            if(!$product||!$product->is_active) {
                throw ValidationException::withMessages(['items'=>'Selecione apenas produtos ativos.']);
            }
            $mills=InventoryService::toMills((string)$item['quantity']);
            $price=$this->cents((string)$item['unit_cost']);
            if($mills<1||$price<0) {
                throw ValidationException::withMessages(['items'=>'Quantidade ou custo inválido.']);
            }
            $base=(int)round($mills*$price/1000,0,PHP_ROUND_HALF_UP);
            $extras=[];
            foreach(['discount','other_costs','external_costs','ipi','icms_st'] as $field) {
                $extras[$field.'_cents']=$this->cents((string)($item[$field]??'0'));
            }
            $line=$base-$extras['discount_cents']+$extras['other_costs_cents']
                +$extras['external_costs_cents']+$extras['ipi_cents']+$extras['icms_st_cents'];
            if($line<0)throw ValidationException::withMessages([
                'items'=>'O desconto do item não pode ultrapassar o valor da linha com seus acréscimos.'
            ]);
            $result[]=array_merge(['product'=>$product,'mills'=>$mills,'unit_cents'=>$price,'line_cents'=>$line],$extras);
        }
        return $result;
    }
    private function requireDraft(Purchase $purchase): void
    {
        if($purchase->status!=='draft') {
            throw ValidationException::withMessages(['purchase'=>'Somente compras em rascunho podem ser alteradas ou recebidas.']);
        }
    }
    private function cents(int|float|string $amount): int
    {
        $text=trim((string)$amount);
        if(!preg_match('/^\d{1,12}(\.\d{1,2})?$/',$text)) {
            throw ValidationException::withMessages(['items'=>'Informe valores monetários válidos.']);
        }
        [$real,$cents]=array_pad(explode('.',$text,2),2,'');
        return ((int)$real)*100+(int)str_pad($cents,2,'0');
    }
    private function money(int $cents): string {return number_format($cents/100,2,'.','');}
}
