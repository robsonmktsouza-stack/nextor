<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Draft and preview totals use the same cent allocation and tax engines as emission. */
final class Nfe55DraftTotalsService
{
    public function calculate(array $items,array $document): array
    {
        $issueDate=(string)($document['issue_date']??'');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$issueDate);
        if(!$date||$date->format('Y-m-d')!==$issueDate)throw new RuntimeException('Informe uma data de emissão válida para calcular os tributos.');
        $gross=[];$normalized=[];$effectiveQuantities=[];
        foreach(array_values($items) as $index=>$item){
            if(!is_array($item))throw new RuntimeException('Confira os produtos da NF-e.');
            $quantity=$item['quantity']??0;$factor=$item['dimension_quantity']??1;
            $unitPrice=$item['unit_price']??0;
            foreach([$quantity,$factor,$unitPrice] as $value){
                if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0){
                    throw new RuntimeException('Confira quantidade e valor dos produtos.');
                }
            }
            $line=NfeTaxMath::line($quantity,$factor,$unitPrice);
            $gross[$index]=$line['gross_cents'];
            $effectiveQuantities[$index]=NfeTaxMath::format($line['quantity_units'],4);
            $normalized[$index]=$item;
        }
        $freight=$this->cents($document['freight']??0);
        $surcharge=$this->cents($document['surcharge']??0);
        $discount=$this->cents($document['discount']??0);
        $freightShares=$this->shares($freight,$gross);
        $otherShares=$this->shares($surcharge,$gross);
        $discountWeights=[];
        foreach($normalized as $index=>$item){
            $available=$gross[$index]+$freightShares[$index]+$otherShares[$index]
                +$this->cents($item['freight']??0)+$this->cents($item['insurance']??0)
                +$this->cents($item['other_expenses']??0)-$this->cents($item['discount']??0);
            if($available<0)throw new RuntimeException('O desconto do item supera seu valor.');
            $discountWeights[$index]=$available;
        }
        if($discount>array_sum($discountWeights))throw new RuntimeException('O desconto geral supera o valor da NF-e.');
        $discountShares=$this->shares($discount,$discountWeights);
        $sum=['products'=>array_sum($gross),'freight'=>$freight,'insurance'=>0,
            'other_expenses'=>0,'discount'=>$discount,'surcharge'=>$surcharge,
            'ipi'=>0,'icms'=>0,'icms_st'=>0,'fcp'=>0,'fcp_st'=>0,'pis'=>0,'cofins'=>0,
            'ibs_uf'=>0,'ibs_municipal'=>0,'cbs'=>0,'icms_desonerated'=>0,'desoneration_deduction'=>0,
            'fcp_st_retained'=>0,'icms_credit'=>0,'difal_destination'=>0,'difal_origin'=>0,'fcp_destination'=>0,'ii'=>0];
        $errors=[];$taxItems=[];$unknown=[];$rtcCount=0;
        foreach($normalized as $index=>$item){
            $tax=Nfe55TaxParameters::forDirection(is_array($item['tax_data']??null)?$item['tax_data']:[],
                (string)($document['operation_type']??'outbound'));
            $itemFreight=$this->cents($item['freight']??0);
            $insurance=$this->cents($item['insurance']??0);
            $other=$this->cents($item['other_expenses']??0);
            $itemDiscount=$this->cents($item['discount']??0);
            $sum['freight']+=$itemFreight;$sum['insurance']+=$insurance;
            $sum['other_expenses']+=$other;$sum['discount']+=$itemDiscount;
            $base=$gross[$index]+$itemFreight+$freightShares[$index]+$insurance+$other
                +$otherShares[$index]-$itemDiscount-$discountShares[$index];
            $result=['number'=>$index+1,'base'=>$base/100];$federalGroups=[];
            foreach(['pis','cofins','ipi'] as $name){
                try {
                    if(trim((string)($tax[$name.'_cst']??''))==='')throw new RuntimeException('Informe o CST de '.strtoupper($name).'.');
                    $federal=app(NfeFederalItemTaxCalculator::class)->calculateGroup($tax,$name,$base);
                    $federalGroups[$name]=$federal;
                    $sum[$name]+=$federal['amount'];
                    $result[$name]=$federal['amount']/100;
                }catch(RuntimeException $error){
                    $unknown[$name]=true;$result[$name]=null;
                    $errors[]='Item '.($index+1).': '.$error->getMessage();
                }
            }
            try {
                $quantity=$effectiveQuantities[$index];
                $icms=app(NfeIcmsItemTaxCalculator::class)->calculate($tax,$base,(string)($document['crt']??''),$quantity);
                foreach(['icms'=>'icms_amount','icms_st'=>'st_amount','fcp'=>'fcp_amount','fcp_st'=>'fcp_st_amount',
                    'icms_desonerated'=>'desonerated_amount','desoneration_deduction'=>'total_deduction',
                    'fcp_st_retained'=>'fcp_st_retained_amount','icms_credit'=>'credit_amount'] as $name=>$key){
                    $sum[$name]+=$icms[$key];$result[$name]=$icms[$key]/100;
                }
            }catch(RuntimeException $error){
                foreach(['icms','icms_st','fcp','fcp_st','icms_desonerated','desoneration_deduction','fcp_st_retained','icms_credit'] as $name){$unknown[$name]=true;$result[$name]=null;}
                $errors[]='Item '.($index+1).': '.$error->getMessage();
            }
            $special=is_array($item['special_data']??null)?$item['special_data']:[];
            if(!empty($special['import_taxes'])){
                try {
                    $import=app(Nfe55ForeignTradeService::class)->calculateImportTaxes($special);
                    $sum['ii']+=$import['ii_amount'];$result['ii']=$import['ii_amount']/100;
                }catch(RuntimeException $error){
                    $unknown['ii']=true;$result['ii']=null;
                    $errors[]='Item '.($index+1).': '.$error->getMessage();
                }
            }
            try {
                $difal=app(Nfe55DifalCalculator::class)->calculate($tax,$document['issue_date']??now()->toDateString());
                foreach(['difal_destination'=>'destination_amount','difal_origin'=>'origin_amount','fcp_destination'=>'fcp_destination_amount'] as $name=>$key){
                    $amount=$difal[$key]??0;
                    $sum[$name]+=$amount;$result[$name]=$amount/100;
                }
            }catch(RuntimeException $error){
                foreach(['difal_destination','difal_origin','fcp_destination'] as $name){$unknown[$name]=true;$result[$name]=null;}
                $errors[]='Item '.($index+1).': '.$error->getMessage();
            }
            try {
                if($result['icms']===null||$result['pis']===null||$result['cofins']===null||$result['difal_destination']===null){
                    throw new RuntimeException('Confira ICMS, PIS e COFINS antes de calcular IBS/CBS.');
                }
                $rtc=app(Nfe55RtcTaxAdapter::class)->calculate($tax,$base-$icms['total_deduction'],
                    (string)($document['crt']??''),$issueDate,[
                        'icms'=>$icms['icms_amount']+$icms['fcp_amount']+($difal['destination_amount']??0)
                            +($difal['origin_amount']??0)+($difal['fcp_destination_amount']??0),
                        'pis'=>$federalGroups['pis']['amount'],'cofins'=>$federalGroups['cofins']['amount'],
                    ],!empty($special['import_taxes']));
                if($rtc!==null){
                    $rtcCount++;
                    foreach(['ibs_uf'=>['gibsuf','vIBSUF'],'ibs_municipal'=>['gibsmun','vIBSMun'],'cbs'=>['gcbs','vCBS']] as $name=>[$section,$field]){
                        $sum[$name]+=$this->cents($rtc[$section][$field]);
                        $result[$name]=(float)$rtc[$section][$field];
                    }
                }
            }catch(RuntimeException $error){
                foreach(['ibs_uf','ibs_municipal','cbs'] as $name){$unknown[$name]=true;$result[$name]=null;}
                $errors[]='Item '.($index+1).': '.$error->getMessage();
            }
            $taxItems[]=$result;
        }
        $sum['total']=$sum['products']+$sum['freight']+$sum['insurance']+$sum['other_expenses']
            +$sum['surcharge']+$sum['ipi']+$sum['icms_st']+$sum['fcp_st']+$sum['ii']-$sum['discount']-$sum['desoneration_deduction'];
        $totals=array_map(static fn(int $value)=>$value/100.0,$sum);
        foreach(array_keys($unknown) as $name)$totals[$name]=null;
        if($rtcCount===0)foreach(['ibs_uf','ibs_municipal','cbs'] as $name)$totals[$name]=null;
        if($rtcCount>0&&$rtcCount!==count($normalized)){
            $errors[]='IBS/CBS preenchido somente em alguns itens; confira a classificação fiscal.';
            foreach(['ibs_uf','ibs_municipal','cbs'] as $name)$totals[$name]=null;
        }
        $totals['rtc_applicable']=$rtcCount>0;
        $totals['tax_calculation_complete']=$errors===[] && $normalized!==[];
        $totals['tax_errors']=$errors;
        $totals['item_taxes']=$taxItems;
        return $totals;
    }

    private function cents(mixed $value): int
    {
        $amount=NfeTaxMath::scaled($value??0,2,'Valor monetário da NF-e');
        if($amount>999999999999999)throw new RuntimeException('Valor monetário excede o limite do documento fiscal.');
        return $amount;
    }

    /** Cumulative half-up allocation mirrors Nfe55IniBuilder. */
    private function shares(int $amount,array $weights): array
    {
        $shares=array_fill(0,count($weights),0);
        if($amount===0)return $shares;
        $weightSum=array_sum($weights);
        if($weightSum<=0)throw new RuntimeException('Não é possível ratear valores sem produtos válidos.');
        $cumulative=0;$distributed=0;
        foreach($weights as $index=>$weight){
            $cumulative+=$weight;
            $next=NfeTaxMath::ratio($amount,$cumulative,$weightSum);
            $shares[$index]=$next-$distributed;$distributed=$next;
        }
        return $shares;
    }
}
