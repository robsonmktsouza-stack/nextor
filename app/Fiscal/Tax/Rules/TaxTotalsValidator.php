<?php

namespace App\Fiscal\Tax\Rules;

use App\Fiscal\Tax\DTO\ResolvedTaxItem;
use App\Fiscal\Tax\DTO\TaxDocumentTotals;
use App\Fiscal\Tax\Exceptions\TaxResolutionException;
use App\Fiscal\Tax\ValueObjects\Money;

final class TaxTotalsValidator
{
    /**
     * @param list<ResolvedTaxItem> $items
     */
    public function assertConsistent(array $items, TaxDocumentTotals $totals): void
    {
        $products = Money::zero();
        $freight = Money::zero();
        $insurance = Money::zero();
        $discount = Money::zero();
        $other = Money::zero();
        $pis = Money::zero();
        $cofins = Money::zero();
        $ibsCbsBase = Money::zero();
        $ibsUf = Money::zero();
        $ibsMun = Money::zero();
        $ibs = Money::zero();
        $cbs = Money::zero();
        $itemTotal = Money::zero();

        foreach ($items as $item) {
            $products = $products->add($item->gross);
            $freight = $freight->add($item->input->freightValue());
            $insurance = $insurance->add($item->input->insuranceValue());
            $discount = $discount->add($item->input->discountValue());
            $other = $other->add($item->input->otherValue());
            $pis = $pis->add($item->tax->pis->amount);
            $cofins = $cofins->add($item->tax->cofins->amount);
            $ibsCbsBase = $ibsCbsBase->add($item->ibsCbsBase);
            $ibsUf = $ibsUf->add($item->tax->ibsCbs->ibsUfAmount);
            $ibsMun = $ibsMun->add($item->tax->ibsCbs->ibsMunAmount);
            $ibs = $ibs->add($item->tax->ibsCbs->ibsAmount);
            $cbs = $cbs->add($item->tax->ibsCbs->cbsAmount);
            $itemTotal = $itemTotal->add($item->itemTotal);
        }

        $expectedNf = $products
            ->subtract($discount)
            ->add($freight)
            ->add($insurance)
            ->add($other);

        $checks = [
            'vProd' => [$products, $totals->products],
            'vFrete' => [$freight, $totals->freight],
            'vSeg' => [$insurance, $totals->insurance],
            'vDesc' => [$discount, $totals->discount],
            'vOutro' => [$other, $totals->other],
            'vPIS' => [$pis, $totals->pis],
            'vCOFINS' => [$cofins, $totals->cofins],
            'vBCIBSCBS' => [$ibsCbsBase, $totals->ibsCbsBase],
            'vIBSUF' => [$ibsUf, $totals->ibsUf],
            'vIBSMun' => [$ibsMun, $totals->ibsMun],
            'vIBS' => [$ibs, $totals->ibs],
            'vCBS' => [$cbs, $totals->cbs],
            'vNF' => [$expectedNf, $totals->nfTotal],
            'vNFTot' => [$itemTotal, $totals->nfTotalWithRtc],
        ];

        foreach ($checks as $field => [$expected, $actual]) {
            if ($expected->compare($actual) !== 0) {
                throw new TaxResolutionException(
                    "Total fiscal {$field} inconsistente: esperado {$expected->toString()}, obtido {$actual->toString()}."
                );
            }
        }
    }
}
