<?php

namespace App\Services\Fiscal;

use App\Models\Customer;
use App\Models\IncomingNfe;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class IncomingNfePurchasePrefill
{
    public function __construct(
        private readonly IncomingNfeParser $parser,
        private readonly IncomingNfeSyncService $sync,
    ) {}

    /** Prepare purchase fields without creating suppliers, products, purchases or stock entries. */
    public function fromInvoice(IncomingNfe $invoice): array
    {
        $expected='fiscal/incoming-nfe/'.$invoice->access_key.'.xml';
        if($invoice->status!=='xml_received'||$invoice->xml_path!==$expected
            ||!Storage::disk('local')->exists($expected)) {
            throw ValidationException::withMessages([
                'xml'=>'XML completo indisponível para esta NF-e.',
            ]);
        }
        // Always re-parse the stored authorized XML instead of trusting a summary.
        $detail=$this->parser->details(Storage::disk('local')->get($expected),$this->sync->companyCnpj());
        if($detail['access_key']!==$invoice->access_key) {
            throw ValidationException::withMessages(['xml'=>'O XML não corresponde à NF-e selecionada.']);
        }
        $cnpj=fn($value)=>preg_replace('/\D/','',(string)$value);
        $supplier=Customer::query()->where('is_supplier',true)->get(['id','document'])
            ->first(fn($row)=>$cnpj($row->document)!==''&&$cnpj($row->document)===$cnpj($detail['supplier_cnpj']));
        $products=Product::query()->where('is_active',true)->get(['id','sku','ean_gtin']);
        $lines=[];$sumOther=0;$sumDiscount=0;
        foreach($detail['items'] as $item) {
            if(count($lines)>=80) {
                throw ValidationException::withMessages(['items'=>'A NF-e excede o limite de 80 itens por compra.']);
            }
            $code=trim((string)$item['code']);
            $ean=trim((string)$item['ean']);
            $matching=$products->filter(fn($p)=>
                ($code!==''&&strcasecmp((string)$p->sku,$code)===0)
                || (preg_match('/^\\d{8,14}$/',$ean)===1&&strcasecmp((string)$p->ean_gtin,$ean)===0)
            );
            $match=$matching->count()===1?$matching->first():null;
            $discount=$this->amount($item['discount']??null);
            $other=$this->amount($item['other_costs']??null);
            $sumDiscount+=$discount;
            $sumOther+=$other;
            $lines[]=[
                'product_id'=>$match?->id,
                'source_description'=>$item['description'],
                'source_code'=>$code,
                'quantity'=>$item['quantity'],
                'unit_cost'=>number_format((float)$item['unit_price'],2,'.',''),
                'discount'=>number_format($discount,2,'.',''),
                'other_costs'=>number_format($other,2,'.',''),
                'external_costs'=>'0.00',
                'ipi'=>number_format($this->amount($item['ipi']??null),2,'.',''),
                'icms_st'=>number_format($this->amount($item['icms_st']??null),2,'.',''),
            ];
        }
        $summary=$detail['summary'];
        $generalDiscount=max(0,$this->amount($summary['discount']??null)-$sumDiscount);
        $otherExpenses=max(0,$this->amount($summary['other_expenses']??null)-$sumOther);
        return [
            'supplier_id'=>$supplier?->id,
            'incoming_nfe_id'=>$invoice->id,
            'operation_date'=>substr((string)$detail['issued_at'],0,10),
            'document_number'=>$summary['number']??null,
            'freight'=>number_format($this->amount($summary['freight']??null),2,'.',''),
            'other_expenses'=>number_format($otherExpenses,2,'.',''),
            'discount'=>number_format($generalDiscount,2,'.',''),
            'items'=>$lines,
        ];
    }

    private function amount(mixed $value): float
    {
        return max(0,(float)($value?:0));
    }
}
