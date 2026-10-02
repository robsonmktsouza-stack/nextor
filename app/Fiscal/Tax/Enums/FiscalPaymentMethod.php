<?php

namespace App\Fiscal\Tax\Enums;

enum FiscalPaymentMethod: string
{
    case CASH = 'cash';
    case PIX = 'pix';
    case DEBIT_CARD = 'debit_card';
    case CREDIT_CARD = 'credit_card';
    case BANK_SLIP = 'bank_slip';
    case BANK_TRANSFER = 'bank_transfer';
    case OTHER = 'other';

    public function tPag(): string
    {
        return match ($this) {
            self::CASH => '01',
            self::CREDIT_CARD => '03',
            self::DEBIT_CARD => '04',
            self::BANK_SLIP => '15',
            self::PIX => '17',
            self::BANK_TRANSFER => '18',
            self::OTHER => '99',
        };
    }

    public function requiresElectronicPaymentDetails(): bool
    {
        return in_array($this, [
            self::CREDIT_CARD,
            self::DEBIT_CARD,
            self::PIX,
        ], true);
    }
}
