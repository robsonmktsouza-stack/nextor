<?php

namespace App\Fiscal\Tax\DTO;

final readonly class FiscalItemTaxResult
{
    public function __construct(
        public IcmsTaxResult $icms,
        public PisTaxResult $pis,
        public CofinsTaxResult $cofins,
        public IbsCbsTaxResult $ibsCbs,
    ) {
    }

    public function toXmlArray(): array
    {
        return [
            'ICMS' => $this->icms->toXmlArray(),
            'PIS' => $this->pis->toXmlArray(),
            'COFINS' => $this->cofins->toXmlArray(),
            'IBSCBS' => $this->ibsCbs->toXmlArray(),
        ];
    }
}
