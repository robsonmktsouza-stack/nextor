<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\Exceptions\TaxConfigurationException;

final readonly class NfceTaxDocumentInput
{
    /**
     * @param list<TaxItemInput> $items
     * @param list<FiscalPaymentInput> $payments
     */
    public function __construct(
        public string $natureOperation,
        public string $cityTaxCode,
        public array $items,
        public array $payments,
        public string $processVersion,
        public ?NfceRecipientInput $recipient = null,
        public string $freightMode = '9',
        public ?string $additionalInfo = null,
    ) {
        if ($natureOperation === '' || strlen($natureOperation) > 60) {
            throw new TaxConfigurationException('Natureza da operação deve possuir de 1 a 60 caracteres.');
        }

        if (!preg_match('/^\d{7}$/', $cityTaxCode)) {
            throw new TaxConfigurationException('Código IBGE do município do fato gerador deve possuir 7 dígitos.');
        }

        if ($items === [] || !array_is_list($items)) {
            throw new TaxConfigurationException('Documento fiscal precisa de ao menos um item.');
        }

        foreach ($items as $item) {
            if (!$item instanceof TaxItemInput) {
                throw new TaxConfigurationException('Lista de itens contém entrada fiscal inválida.');
            }
        }

        if ($payments === [] || !array_is_list($payments)) {
            throw new TaxConfigurationException('NFC-e precisa de ao menos um meio de pagamento.');
        }

        foreach ($payments as $payment) {
            if (!$payment instanceof FiscalPaymentInput) {
                throw new TaxConfigurationException('Lista de pagamentos contém entrada fiscal inválida.');
            }
        }

        if ($processVersion === '' || strlen($processVersion) > 20) {
            throw new TaxConfigurationException('Versão do processo emissor deve possuir de 1 a 20 caracteres.');
        }

        if (!in_array($freightMode, ['0', '1', '2', '3', '4', '9'], true)) {
            throw new TaxConfigurationException('Modalidade do frete inválida.');
        }
    }
}
