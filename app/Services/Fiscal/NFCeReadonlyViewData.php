<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;

/**
 * Dados congelados do documento emitido: XML autorizado prevalece sobre a
 * venda, preço e cadastro atuais. Nunca reprocessar a nota para visualizá-la.
 */
final class NFCeReadonlyViewData
{
    public function forDocument(FiscalDocumentJob $document): array
    {
        $snapshot=is_array($document->source_snapshot) ? $document->source_snapshot : [];
        $company=clone CompanySetting::current();

        $rows=[];
        foreach (($snapshot['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $quantity=(float)($item['quantity'] ?? 0);
            $price=(float)($item['unit_price'] ?? 0);
            $discount=(float)($item['discount'] ?? 0);
            $rows[]=[
                'name'=>(string)($item['name'] ?? 'Produto'),
                'sku'=>(string)($item['sku'] ?? ''),
                'quantity'=>$quantity,
                'unit_price'=>$price,
                'discount'=>$discount,
                'total'=>(float)($item['line_total'] ?? max(0,round($price*$quantity-$discount,2))),
            ];
        }

        $paymentNames=[
            '01'=>'Dinheiro','02'=>'Cheque','03'=>'Cartão de crédito',
            '04'=>'Cartão de débito','05'=>'Crédito loja','10'=>'Vale alimentação',
            '11'=>'Vale refeição','12'=>'Vale presente','13'=>'Vale combustível',
            '15'=>'Boleto bancário','16'=>'Depósito bancário','17'=>'PIX',
            '18'=>'Transferência bancária','19'=>'Programa de fidelidade',
            '90'=>'Sem pagamento','99'=>'Outros',
            'cash'=>'Dinheiro','money'=>'Dinheiro','pix'=>'PIX',
            'credit_card'=>'Cartão de crédito','debit_card'=>'Cartão de débito',
            'bank_slip'=>'Boleto bancário','bank_transfer'=>'Transferência bancária',
            'transfer'=>'Transferência bancária',
        ];
        $payments=[];
        foreach (($snapshot['payments'] ?? []) as $payment) {
            if (!is_array($payment)) continue;
            $code=(string)($payment['payment_kind'] ?? $payment['payment_method'] ?? '');
            $payments[]=[
                'name'=>$paymentNames[$code] ?? ($code ?: 'Não informado'),
                'amount'=>(float)($payment['amount'] ?? 0),
            ];
        }

        $subtotal=array_reduce($rows,static fn($sum,$row)=>$sum+round($row['quantity']*$row['unit_price'],2),0.0);
        $discount=array_sum(array_column($rows,'discount'));
        $totals=[
            'subtotal'=>$subtotal,
            'discount'=>$discount,
            'total'=>is_numeric($snapshot['total'] ?? null)
                ? (float)$snapshot['total'] : max(0,round($subtotal-$discount,2)),
        ];
        $issueAt=$document->prepared_at ?? $document->created_at;
        $nature='Venda de mercadoria';
        $presence='Operação presencial';
        $consumerDocument=(string)($snapshot['consumer_document'] ?? '');
        $consumerName=(string)($snapshot['consumer_name'] ?? '');
        $notes='';
        $xmlLoaded=false;

        $path=(string)$document->xml_path;
        $base='fiscal/nfce/'.$document->id.'/';
        $allowed=[
            $base.'authorized.xml',
            $base.'authorized-recovered.xml',
            $base.'signed.xml',
        ];
        if (in_array($path,$allowed,true) && Storage::disk('local')->exists($path)) {
            $xml=Storage::disk('local')->get($path);
            $dom=new DOMDocument('1.0','UTF-8');
            $previous=libxml_use_internal_errors(true);
            try {
                $xmlLoaded=$dom->loadXML($xml,LIBXML_NONET)
                    && $dom->documentElement?->namespaceURI==='http://www.portalfiscal.inf.br/nfe';
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            if ($xmlLoaded) {
                $xp=new DOMXPath($dom);
                $xp->registerNamespace('n','http://www.portalfiscal.inf.br/nfe');
                $read=static fn(string $path,? \DOMNode $context=null): string =>
                    trim((string)$xp->evaluate('string('.$path.')',$context));
                $root='//n:NFe/n:infNFe/n:';

                foreach ([
                    'legal_name'=>'emit/n:xNome',
                    'trade_name'=>'emit/n:xFant',
                    'document'=>'emit/n:CNPJ',
                    'state_registration'=>'emit/n:IE',
                    'crt'=>'emit/n:CRT',
                    'address'=>'emit/n:enderEmit/n:xLgr',
                    'address_number'=>'emit/n:enderEmit/n:nro',
                    'district'=>'emit/n:enderEmit/n:xBairro',
                    'city'=>'emit/n:enderEmit/n:xMun',
                    'state'=>'emit/n:enderEmit/n:UF',
                ] as $attribute=>$xmlNode) {
                    $value=$read($root.$xmlNode);
                    if ($value!=='') $company->setAttribute($attribute,$value);
                }

                $nature=$read($root.'ide/n:natOp') ?: $nature;
                $issued=$read($root.'ide/n:dhEmi');
                if ($issued!=='') {
                    try {$issueAt=\Carbon\Carbon::parse($issued);}
                    catch (\Throwable) {}
                }
                $presenceCodes=[
                    '0'=>'Não se aplica','1'=>'Operação presencial','2'=>'Operação pela Internet',
                    '3'=>'Operação por teleatendimento','4'=>'Entrega a domicílio',
                    '5'=>'Operação presencial fora do estabelecimento','9'=>'Operação não presencial',
                ];
                $presenceCode=$read($root.'ide/n:indPres');
                if ($presenceCode!=='') $presence=$presenceCodes[$presenceCode] ?? 'Código '.$presenceCode;

                $consumerDocument=$read($root.'dest/n:CPF')
                    ?: $read($root.'dest/n:CNPJ') ?: $consumerDocument;
                $consumerName=$read($root.'dest/n:xNome') ?: $consumerName;
                $notes=$read($root.'infAdic/n:infCpl');

                // Priorizar produtos efetivamente enviados à SEFAZ.
                $xmlRows=[];
                foreach ($xp->query($root.'det') as $detail) {
                    $at=static fn(string $node): string =>
                        trim((string)$xp->evaluate('string('.$node.')',$detail));
                    $quantity=(float)$at('n:prod/n:qCom');
                    $price=(float)$at('n:prod/n:vUnCom');
                    $discount=(float)$at('n:prod/n:vDesc');
                    $xmlRows[]=[
                        'name'=>$at('n:prod/n:xProd') ?: 'Produto',
                        'sku'=>$at('n:prod/n:cProd'),
                        'quantity'=>$quantity,
                        'unit_price'=>$price,
                        'discount'=>$discount,
                        'total'=>max(0,round((float)$at('n:prod/n:vProd')-$discount,2)),
                    ];
                }
                if ($xmlRows) $rows=$xmlRows;

                $xmlPayments=[];
                foreach ($xp->query($root.'pag/n:detPag') as $payment) {
                    $at=static fn(string $node): string =>
                        trim((string)$xp->evaluate('string('.$node.')',$payment));
                    $kind=$at('n:tPag');
                    $xmlPayments[]=[
                        'name'=>$paymentNames[$kind] ?? ('Código '.$kind),
                        'amount'=>(float)$at('n:vPag'),
                    ];
                }
                if ($xmlPayments) $payments=$xmlPayments;

                $xmlSubtotal=$read($root.'total/n:ICMSTot/n:vProd');
                $xmlDiscount=$read($root.'total/n:ICMSTot/n:vDesc');
                $xmlTotal=$read($root.'total/n:ICMSTot/n:vNF');
                if ($xmlSubtotal!=='') $totals['subtotal']=(float)$xmlSubtotal;
                if ($xmlDiscount!=='') $totals['discount']=(float)$xmlDiscount;
                if ($xmlTotal!=='') $totals['total']=(float)$xmlTotal;
            }
        }

        return [
            'readOnlyDocument'=>$document,
            'company'=>$company,'settings'=>app(FiscalDocumentSettings::class),
            'products'=>[],'methods'=>[],'customers'=>[],'sales'=>[],
            'canCreateSale'=>false,
            'frozenRows'=>$rows,'frozenPaymentRows'=>$payments,
            'frozenTotals'=>$totals,
            'frozenPaymentTotal'=>array_sum(array_column($payments,'amount')),
            'frozenConsumerDocument'=>$consumerDocument,
            'frozenConsumerName'=>$consumerName,
            'frozenNotes'=>$notes,
            'frozenIssueDate'=>$issueAt?->format('d/m/Y H:i') ?? '—',
            'frozenNature'=>$nature,'frozenPresence'=>$presence,
            'xmlLoaded'=>$xmlLoaded,
        ];
    }
}
