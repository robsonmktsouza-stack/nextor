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
        $profile=$this->profiles->calculate([
            'quantity'=>1,'unit_price'=>($explicit ? $base+$deduction : $netBaseCents)/100,
            'discount'=>0,'tax_defaults'=>$tax,
        ],$crt,$issueDate,$legacy);
        if ($profile===null) return null;
        if ($import && !$explicit) throw new RuntimeException('RTC da importação exige base IBS/CBS expressamente aprovada para os tributos e despesas aduaneiras.');
        if ($base < 0) throw new RuntimeException('RTC: tributos legados superam o valor líquido do item.');
        $zeroRated=$tax['ibs_cbs_cst']==='200' && $tax['ibs_cbs_class']==='200003';
        $amounts=[];
        foreach (['uf'=>['gibsuf','ibs_uf_rate','vIBSUF'],
            'municipal'=>['gibsmun','ibs_municipal_rate','vIBSMun'],
            'cbs'=>['gcbs','cbs_rate','vCBS']] as $name=>[$group,$rate,$field]) {
            $amounts[$name]=$zeroRated ? 0 : NfeTaxMath::percent($base,$tax[$rate],'RTC '.$rate);
            $profile[$group][$field]=NfeTaxMath::format($amounts[$name]);
        }
        $profile['gibscbs']['vBC']=NfeTaxMath::format($base);
        $profile['gibscbs']['vIBS']=NfeTaxMath::format($amounts['uf']+$amounts['municipal']);
        return $profile;
    }
}
