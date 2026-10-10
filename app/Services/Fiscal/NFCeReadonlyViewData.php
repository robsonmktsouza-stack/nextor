<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Storage;
use DOMDocument;
use DOMXPath;

/** Read-only issuer/commerce data are frozen at issuance, not rebuilt from today's catalog. */
final class NFCeReadonlyViewData
{
    public function forDocument(FiscalDocumentJob $document): array
    {
        $snapshot=$document->source_snapshot ?? [];
        $company=clone CompanySetting::current();
        $items=[];
        $products=[];
        foreach (($snapshot['items'] ?? []) as $index=>$item) {
            if (!is_array($item)) continue;
            $id='snapshot-'.($index+1);
            $products[]=[
                'id'=>$id,
                'name'=>(string)($item['name'] ?? 'Produto da NFC-e'),
                'sku'=>(string)($item['sku'] ?? ''),
                'sale_price'=>(string)($item['unit_price'] ?? '0'),
                'ean_gtin'=>(string)($item['gtin'] ?? ''),
            ];
            $items[]=[
                'product_id'=>$id,
                'quantity'=>(string)($item['quantity'] ?? '0'),
                'unit_price'=>(string)($item['unit_price'] ?? '0'),
                'discount'=>(string)($item['discount'] ?? '0'),
            ];
        }

        $paymentRows=array_values(array_filter($snapshot['payments'] ?? [], 'is_array'));
        $codes=array_values(array_unique(array_filter(array_map(
            static fn(array $row)=>(string)($row['payment_method'] ?? ''),$paymentRows
        ))));
        $names=$codes?PaymentMethod::query()->whereIn('code',$codes)->pluck('name','code')->all():[];
        $kindNames=[
            'cash'=>'Dinheiro','money'=>'Dinheiro','pix'=>'PIX',
            'credit_card'=>'Cartão de crédito','debit_card'=>'Cartão de débito',
            'bank_slip'=>'Boleto','bank_transfer'=>'Transferência bancária',
            'transfer'=>'Transferência bancária',
        ];
        $methods=[];
        $payments=[];
        foreach ($paymentRows as $index=>$row) {
            $code=(string)($row['payment_method'] ?? '');
            if ($code==='') $code='historical-'.$index;
            $kind=(string)($row['payment_kind'] ?? '');
            $methods[$code]=['code'=>$code,'name'=>$names[$code] ?? ($kindNames[$kind] ?? $code)];
            $payments[]=[
                'payment_method'=>$code,
                'amount'=>(string)($row['amount'] ?? '0'),
            ];
        }

        $issuedAt=$document->prepared_at ?? $document->created_at;
        $nature='VENDA DE MERCADORIA';
        $consumerDocument=(string)($snapshot['consumer_document'] ?? '');
        $consumerName=(string)($snapshot['consumer_name'] ?? '');
        $xmlLoaded=false;

        // Prefer the actual XML archived at authorization, including for cancelled documents.
        $path=(string)$document->xml_path;
        $safePaths=[
            'fiscal/nfce/'.$document->id.'/authorized.xml',
            'fiscal/nfce/'.$document->id.'/authorized-recovered.xml',
            'fiscal/nfce/'.$document->id.'/signed.xml',
        ];
        if (in_array($path,$safePaths,true) && Storage::disk('local')->exists($path)) {
            $xml=Storage::disk('local')->get($path);
            $dom=new DOMDocument('1.0','UTF-8');
            $old=libxml_use_internal_errors(true);
            try {
                $xmlLoaded=$dom->loadXML($xml,LIBXML_NONET) && $dom->documentElement?->namespaceURI==='http://www.portalfiscal.inf.br/nfe';
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($old);
            }
            if ($xmlLoaded) {
                $xp=new DOMXPath($dom);
                $xp->registerNamespace('n','http://www.portalfiscal.inf.br/nfe');
                $get=static fn(string $query)=>trim((string)$xp->evaluate('string('.$query.')'));
                $emitter=[
                    'legal_name'=>$get('//n:NFe/n:infNFe/n:emit/n:xNome'),
                    'trade_name'=>$get('//n:NFe/n:infNFe/n:emit/n:xFant'),
                    'document'=>$get('//n:NFe/n:infNFe/n:emit/n:CNPJ'),
                    'state_registration'=>$get('//n:NFe/n:infNFe/n:emit/n:IE'),
                    'crt'=>$get('//n:NFe/n:infNFe/n:emit/n:CRT'),
                    'address'=>$get('//n:NFe/n:infNFe/n:emit/n:enderEmit/n:xLgr'),
                    'address_number'=>$get('//n:NFe/n:infNFe/n:emit/n:enderEmit/n:nro'),
                    'district'=>$get('//n:NFe/n:infNFe/n:emit/n:enderEmit/n:xBairro'),
                    'city'=>$get('//n:NFe/n:infNFe/n:emit/n:enderEmit/n:xMun'),
                    'state'=>$get('//n:NFe/n:infNFe/n:emit/n:enderEmit/n:UF'),
                ];
                foreach ($emitter as $field=>$value) {
                    if ($value!=='') $company->setAttribute($field,$value);
                }
                $nature=$get('//n:NFe/n:infNFe/n:ide/n:natOp') ?: $nature;
                $date=$get('//n:NFe/n:infNFe/n:ide/n:dhEmi');
                if ($date!=='') {
                    try {$issuedAt=\Carbon\Carbon::parse($date);} catch (\Throwable) {}
                }
                $consumerDocument=$get('//n:NFe/n:infNFe/n:dest/n:CPF')
                    ?: $get('//n:NFe/n:infNFe/n:dest/n:CNPJ') ?: $consumerDocument;
                $consumerName=$get('//n:NFe/n:infNFe/n:dest/n:xNome') ?: $consumerName;
            }
        }

        return [
            'company'=>$company,
            'settings'=>app(FiscalDocumentSettings::class),
            'products'=>$products,'methods'=>array_values($methods),
            'customers'=>[],'sales'=>[],'canCreateSale'=>true,
            'readOnlyDocument'=>$document,
            'frozenItems'=>$items,'frozenPayments'=>$payments,
            'frozenConsumerDocument'=>$consumerDocument,
            'frozenConsumerName'=>$consumerName,
            'frozenNotes'=>(string)($document->sale?->notes ?? ''),
            'frozenIssueDate'=>$issuedAt?->format('d/m/Y H:i') ?? '—',
            'frozenNature'=>$nature,
            'xmlLoaded'=>$xmlLoaded,
        ];
    }
}
