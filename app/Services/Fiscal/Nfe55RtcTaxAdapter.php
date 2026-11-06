<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Shares the validated RTC profiles with NFC-e, while computing model 55 amounts exactly in cents. */
final class Nfe55RtcTaxAdapter
{
    public function __construct(private readonly NFCeRtcTaxService $profiles) {}

    public function calculate(array $tax,int $netBaseCents,string $crt,string $issueDate,array $legacyAmounts,bool $import=false): ?array
    {
        if ($netBaseCents < 0) throw new RuntimeException('RTC: valor líquido do item negativo.');
        $canonical=(string)($tax['ibs_cbs_cst']??'');
        $ibs=trim((string)($tax['ibs_cst']??''))?:$canonical;
        $cbs=trim((string)($tax['cbs_cst']??''))?:$canonical;
        $classification=trim((string)($tax['tax_classification_code']??''))?:trim((string)($tax['ibs_cbs_class']??''));
        if ($ibs!=='' || $cbs!=='' || $classification!=='') {
            if ($ibs==='' || $cbs==='' || $ibs!==$cbs) throw new RuntimeException('RTC: CST do IBS e CBS devem corresponder.');
            $tax['ibs_cbs_cst']=$ibs;$tax['ibs_cbs_class']=$classification;
        }
        $deduction=0;
        $transition=$issueDate >= '2026-01-01' && $issueDate <= '2032-12-31';
        $legacy=[];
        foreach (['icms'=>'vICMS','pis'=>'vPIS','cofins'=>'vCOFINS'] as $name=>$field) {
            $amount=$legacyAmounts[$name]??null;
            if (!is_int($amount) || $amount < 0) throw new RuntimeException('RTC: informe o valor calculado de '.strtoupper($name).'.');
            $legacy[$name]=[$field=>NfeTaxMath::format($amount)];
            if ($transition) $deduction+=$amount;
        }
        $explicit=isset($tax['ibs_cbs_base']) && $tax['ibs_cbs_base'] !== '';
        // An explicit RTC base is final. Never subtract the legacy taxes from it a second time.
        $base=$explicit ? NfeTaxMath::scaled($tax['ibs_cbs_base'],2,'Base IBS/CBS') : $netBaseCents-$deduction;
        // Validate IS separately; the shared NFC-e service does not implement its group.
        $selective=(new Nfe55SelectiveTaxCalculator())->calculate($tax,$issueDate);
        if($selective!==null && !$explicit)throw new RuntimeException('RTC: informe base final explícita com o tratamento do Imposto Seletivo.');
        $profileTax=array_filter($tax,static fn($key)=>!str_starts_with((string)$key,'is_'),ARRAY_FILTER_USE_KEY);
        if($ibs==='' && $cbs==='' && $classification==='') {
            foreach($tax as $key=>$value){if(str_starts_with((string)$key,'rtc_') && $value!==null && $value!=='')throw new RuntimeException('RTC: informe CST e classificação para os grupos específicos.');}
            return $this->profiles->calculate([
                'quantity'=>1,'unit_price'=>$netBaseCents/100,'discount'=>0,'tax_defaults'=>$profileTax,
            ],$crt,$issueDate,$legacy);
        }
        if(in_array($crt,['1','4'],true))throw new RuntimeException('RTC: Simples Nacional exige opção e enquadramento específicos; não aplicar o regime regular automaticamente.');
        if(!in_array($crt,['2','3'],true))throw new RuntimeException('RTC: CRT inválido.');
        if($import && !$explicit)throw new RuntimeException('RTC da importação exige base IBS/CBS expressamente aprovada para os tributos e despesas aduaneiras.');
        if($base<0)throw new RuntimeException('RTC: tributos legados superam o valor líquido do item.');
        return (new Nfe55RtcConfiguredCalculator())->calculate($profileTax,$base,$issueDate);
    }
}
