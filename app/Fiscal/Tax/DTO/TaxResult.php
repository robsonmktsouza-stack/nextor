<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\ValueObjects\Money;

final readonly class TaxResult
{
    /**
     * @param list<ResolvedTaxItem> $items
     * @param list<FiscalPaymentInput> $payments
     */
    public function __construct(
        public array $items,
        public TaxDocumentTotals $totals,
        public array $payments,
        public Money $change,
    ) {
    }

    public function toResolvedSnapshot(NfceTaxDocumentInput $input): array
    {
        $snapshot = [
            'identification' => [
                'nature_operation' => $input->natureOperation,
                'operation_type' => '1',
                'destination' => '1',
                'city_tax_code' => $input->cityTaxCode,
                'print_type' => '4',
                'purpose' => '1',
                'final_consumer' => '1',
                'presence' => '1',
                'process' => '0',
                'process_version' => $input->processVersion,
            ],
            'items' => array_map(
                static fn (ResolvedTaxItem $item) => $item->toSnapshotArray(),
                $this->items,
            ),
            'totals' => $this->totals->toSnapshotArray(),
            'freight_mode' => $input->freightMode,
            'payments' => array_map(
                static fn (FiscalPaymentInput $payment) => $payment->toXmlArray(),
                $this->payments,
            ),
        ];

        if ($this->change->compare(Money::zero()) > 0) {
            $snapshot['change'] = $this->change->toString();
        }

        if ($input->recipient !== null) {
            $snapshot['recipient'] = $input->recipient->toSnapshotArray();
        }

        if ($input->additionalInfo !== null && $input->additionalInfo !== '') {
            $snapshot['additional_info'] = $input->additionalInfo;
        }

        return $snapshot;
    }
}
