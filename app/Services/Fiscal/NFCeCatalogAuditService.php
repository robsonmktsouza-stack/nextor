<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\Product;
use RuntimeException;

/**
 * Auditoria de mercadorias ativas sem venda, baixa de estoque, reserva de
 * numeração, ACBr ou transmissão. Emprega as mesmas regras do emissor.
 */
final class NFCeCatalogAuditService
{
    public function __construct(
        private readonly NFCeFiscalProfileService $profiles,
        private readonly NFCeTaxCalculationService $calculator,
        private readonly NFCeRtcTaxService $rtc,
        private readonly NFCeItemValueGuard $valueGuard,
    ) {}

    /**
     * @return array{checked:int,invalid:int,examples:list<string>}
     */
    public function run(int $maxExamples = 20): array
    {
        $company = CompanySetting::current();
        $crt = (string) $company->crt;
        $uf = (string) $company->state;
        $checked = 0;
        $invalid = 0;
        $examples = [];
        $maxExamples = max(1, min(100, $maxExamples));

        Product::query()->where('is_active', true)->orderBy('id')
            ->chunkById(100, function ($products) use (
                &$checked, &$invalid, &$examples, $maxExamples, $crt, $uf
            ): void {
                foreach ($products as $product) {
                    // Serviços não fazem parte da NFC-e modelo 65.
                    if (in_array((string) $product->usage_type, ['service', 'services'], true)) {
                        continue;
                    }
                    $checked++;
                    $item = [
                        'item_type' => 'product',
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'ncm' => $product->ncm,
                        'cest' => $product->cest,
                        'gtin' => $product->ean_gtin,
                        'origin' => $product->origin,
                        'quantity' => '1.000',
                        'unit_price' => (string) ($product->sale_price ?? '0.00'),
                        'discount' => '0.00',
                        'tax_defaults' => is_array($product->tax_defaults) ? $product->tax_defaults : [],
                        'fiscal_tax_group_id' => $product->fiscal_tax_group_id,
                    ];
                    try {
                        $this->valueGuard->verify($item);
                        $this->valueGuard->verifyGtin($item['gtin']);
                        $profile = $this->profiles->classify($item, now()->toDateString(), $crt, $uf);
                        $item['tax_defaults'] = array_replace($item['tax_defaults'], $profile['tax']);
                        $taxes = $this->calculator->calculate($item, $crt);
                        $this->rtc->calculate($item, $crt, now()->toDateString(), $taxes);
                    } catch (RuntimeException $e) {
                        $invalid++;
                        if (count($examples) < $maxExamples) {
                            $examples[] = sprintf(
                                '#%d %s: %s',
                                $product->id,
                                mb_substr((string) $product->name, 0, 80),
                                $e->getMessage()
                            );
                        }
                    }
                }
            });

        return ['checked' => $checked, 'invalid' => $invalid, 'examples' => $examples];
    }
}
