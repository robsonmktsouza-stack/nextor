<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** EC 87/2015 destination sharing, supplied only for a legally classified interstate operation. */
final class Nfe55DifalCalculator
{
    public function calculate(array $tax, string $issueDate): ?array
    {
        $names=['difal_base','difal_fcp_base','difal_internal_rate','difal_interstate_rate','difal_share_rate','difal_fcp_rate'];
        $present=array_filter($names,static fn(string $name)=>isset($tax[$name]) && $tax[$name] !== '');
        if (!$present) return null;
        if (count($present) !== count($names)) throw new RuntimeException('DIFAL exige bases, alíquotas e percentual de partilha explícitos.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$issueDate);
        if (!$date || $date->format('Y-m-d') !== $issueDate || $issueDate < '2019-01-01') {
            throw new RuntimeException('DIFAL: este fluxo admite a partilha integral de destino vigente desde 2019.');
        }
        $base=NfeTaxMath::scaled($tax['difal_base'],2,'DIFAL base de destino');
        $fcpBase=NfeTaxMath::scaled($tax['difal_fcp_base'],2,'FCP base de destino');
        $internal=NfeTaxMath::rate($tax['difal_internal_rate'],'DIFAL alíquota interna');
        $interstate=NfeTaxMath::rate($tax['difal_interstate_rate'],'DIFAL alíquota interestadual');
        if (!in_array($interstate,[40000,70000,120000],true)) throw new RuntimeException('DIFAL: alíquota interestadual deve corresponder a 4%, 7% ou 12%, conforme enquadramento.');
        $share=NfeTaxMath::rate($tax['difal_share_rate'],'DIFAL partilha');
        if ($share !== 1000000) throw new RuntimeException('DIFAL: a partilha de destino deve ser 100% desde 2019.');
        if ($internal < $interstate) throw new RuntimeException('DIFAL: alíquota interna inferior à interestadual exige tratamento fiscal específico.');
        $fcpRate=NfeTaxMath::rate($tax['difal_fcp_rate'],'FCP de destino');
        $difference=NfeTaxMath::product($base,$internal-$interstate,6);
        $destination=NfeTaxMath::product($difference,$share,6);
        $origin=$difference-$destination;
        $fcp=NfeTaxMath::product($fcpBase,$fcpRate,6);
        return ['fields'=>[
            'vBCUFDest'=>NfeTaxMath::format($base),'vBCFCPUFDest'=>NfeTaxMath::format($fcpBase),
            'pFCPUFDest'=>NfeTaxMath::format($fcpRate,4),'pICMSUFDest'=>NfeTaxMath::format($internal,4),
            'pICMSInter'=>NfeTaxMath::format($interstate,4),'pICMSInterPart'=>NfeTaxMath::format($share,4),
            'vFCPUFDest'=>NfeTaxMath::format($fcp),'vICMSUFDest'=>NfeTaxMath::format($destination),
            'vICMSUFRemet'=>NfeTaxMath::format($origin),
        ],'fcp_destination_amount'=>$fcp,'destination_amount'=>$destination,'origin_amount'=>$origin];
    }
}
