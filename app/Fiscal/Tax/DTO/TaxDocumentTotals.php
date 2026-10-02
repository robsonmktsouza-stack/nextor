<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\ValueObjects\Money;

final readonly class TaxDocumentTotals
{
    public function __construct(
        public Money $products,
        public Money $freight,
        public Money $insurance,
        public Money $discount,
        public Money $other,
        public Money $pis,
        public Money $cofins,
        public Money $ibsCbsBase,
        public Money $ibsUf,
        public Money $ibsMun,
        public Money $ibs,
        public Money $cbs,
        public Money $nfTotal,
        public Money $nfTotalWithRtc,
    ) {
    }

    public function toSnapshotArray(): array
    {
        $zero = Money::zero()->toString();

        return [
            'ICMSTot' => [
                'vBC' => $zero,
                'vICMS' => $zero,
                'vICMSDeson' => $zero,
                'vFCP' => $zero,
                'vBCST' => $zero,
                'vST' => $zero,
                'vFCPST' => $zero,
                'vFCPSTRet' => $zero,
                'vProd' => $this->products->toString(),
                'vFrete' => $this->freight->toString(),
                'vSeg' => $this->insurance->toString(),
                'vDesc' => $this->discount->toString(),
                'vII' => $zero,
                'vIPI' => $zero,
                'vIPIDevol' => $zero,
                'vPIS' => $this->pis->toString(),
                'vCOFINS' => $this->cofins->toString(),
                'vOutro' => $this->other->toString(),
                'vNF' => $this->nfTotal->toString(),
            ],
            'IBSCBSTot' => [
                'vBCIBSCBS' => $this->ibsCbsBase->toString(),
                'gIBS' => [
                    'gIBSUF' => [
                        'vDif' => $zero,
                        'vDevTrib' => $zero,
                        'vIBSUF' => $this->ibsUf->toString(),
                    ],
                    'gIBSMun' => [
                        'vDif' => $zero,
                        'vDevTrib' => $zero,
                        'vIBSMun' => $this->ibsMun->toString(),
                    ],
                    'vIBS' => $this->ibs->toString(),
                    'vCredPres' => $zero,
                    'vCredPresCondSus' => $zero,
                ],
                'gCBS' => [
                    'vDif' => $zero,
                    'vDevTrib' => $zero,
                    'vCBS' => $this->cbs->toString(),
                    'vCredPres' => $zero,
                    'vCredPresCondSus' => $zero,
                ],
            ],
            'vNFTot' => $this->nfTotalWithRtc->toString(),
        ];
    }
}
