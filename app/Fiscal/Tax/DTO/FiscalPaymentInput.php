<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\Enums\FiscalPaymentMethod;
use App\Fiscal\Tax\Exceptions\TaxConfigurationException;
use App\Fiscal\Tax\ValueObjects\Money;

final readonly class FiscalPaymentInput
{
    public function __construct(
        public FiscalPaymentMethod $method,
        public Money $amount,
        public ?string $indicator = null,
        public ?PaymentCardInput $card = null,
    ) {
        if (
            in_array($method, [FiscalPaymentMethod::CREDIT_CARD, FiscalPaymentMethod::DEBIT_CARD], true)
            && $card === null
        ) {
            throw new TaxConfigurationException(
                'Pagamento por cartão exige informar ao menos o tipo de integração (tpIntegra).'
            );
        }

        if ($indicator !== null && !in_array($indicator, ['0', '1'], true)) {
            throw new TaxConfigurationException('Indicador de pagamento deve ser 0 (à vista) ou 1 (a prazo).');
        }
    }

    public function toXmlArray(): array
    {
        $data = [];

        if ($this->indicator !== null) {
            $data['indicator'] = $this->indicator;
        }

        $data['type'] = $this->method->tPag();
        $data['amount'] = $this->amount->toString();

        if ($this->card !== null) {
            $data['card'] = $this->card->toXmlArray();
        }

        return $data;
    }
}
