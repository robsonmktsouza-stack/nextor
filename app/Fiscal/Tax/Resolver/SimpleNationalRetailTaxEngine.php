<?php

namespace App\Fiscal\Tax\Resolver;

use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Tax\Contracts\TaxEngineInterface;
use App\Fiscal\Tax\DTO\CofinsTaxResult;
use App\Fiscal\Tax\DTO\FiscalItemTaxResult;
use App\Fiscal\Tax\DTO\IbsCbsTaxResult;
use App\Fiscal\Tax\DTO\IcmsTaxResult;
use App\Fiscal\Tax\DTO\NfceTaxDocumentInput;
use App\Fiscal\Tax\DTO\PisTaxResult;
use App\Fiscal\Tax\DTO\ResolvedTaxItem;
use App\Fiscal\Tax\DTO\TaxDocumentTotals;
use App\Fiscal\Tax\DTO\TaxResult;
use App\Fiscal\Tax\Enums\IcmsCsosn;
use App\Fiscal\Tax\Enums\RtcMode;
use App\Fiscal\Tax\Exceptions\TaxConfigurationException;
use App\Fiscal\Tax\Rules\TaxTotalsValidator;
use App\Fiscal\Tax\ValueObjects\IbsCbsClassification;
use App\Fiscal\Tax\ValueObjects\Money;
use App\Fiscal\Tax\ValueObjects\TaxRate;
use App\Fiscal\Tax\Versions\IbsCbsClassificationCatalog;
use App\Fiscal\Tax\Versions\Rtc2026Rates;
use DateTimeInterface;

final class SimpleNationalRetailTaxEngine implements TaxEngineInterface
{
    private const CONTRIBUTION_BASE_MODE = 'item_operation_value';
    private const RTC_BASE_MODE = 'nt2025_002_ub16_2026';

    public function __construct(
        private readonly FiscalTaxRuleResolver $rules,
        private readonly IbsCbsClassificationCatalog $classifications,
        private readonly TaxTotalsValidator $totalsValidator,
    ) {
    }

    public function resolve(
        FiscalCompany $company,
        NfceTaxDocumentInput $input,
        DateTimeInterface $issueAt,
    ): TaxResult {
        if ((string) $company->crt !== '1') {
            throw new TaxConfigurationException(
                'Primeiro cenário do Nextor Fiscal suporta apenas empresa CRT=1 (Simples Nacional).'
            );
        }

        if ($issueAt->format('Y') !== '2026') {
            throw new TaxConfigurationException(
                'A versão inicial do Tax Engine está fechada para as regras RTC vigentes em 2026.'
            );
        }

        $resolvedItems = [];

        foreach ($input->items as $itemInput) {
            $product = $itemInput->product;
            $label = $this->productLabel($product);

            $ncm = preg_replace('/\D/', '', (string) ($product->ncm ?? '')) ?? '';
            if (!preg_match('/^\d{8}$/', $ncm)) {
                throw new TaxConfigurationException("Produto {$label} não possui NCM válido de 8 dígitos.");
            }

            $origin = (string) ($product->origin ?? '');
            if (!preg_match('/^[0-8]$/', $origin)) {
                throw new TaxConfigurationException("Produto {$label} não possui origem fiscal válida.");
            }

            if (trim((string) ($product->sku ?? '')) === '') {
                throw new TaxConfigurationException("Produto {$label} não possui código próprio (SKU).");
            }

            if (trim((string) ($product->unit ?? '')) === '') {
                throw new TaxConfigurationException("Produto {$label} não possui unidade comercial.");
            }

            if ((bool) $product->different_tax_unit) {
                throw new TaxConfigurationException(
                    "Produto {$label} usa unidade tributável diferente, cenário ainda não suportado sem fator de conversão fiscal."
                );
            }

            $rule = $this->rules->resolve($company, $product, $issueAt);

            if (!preg_match('/^5\d{3}$/', (string) $rule->cfop)) {
                throw new TaxConfigurationException(
                    "Produto {$label} não possui CFOP interno válido na regra tributária."
                );
            }

            $csosn = IcmsCsosn::tryFrom((string) $rule->icms_csosn);
            if ($csosn === null) {
                throw new TaxConfigurationException(
                    "Produto {$label} possui CSOSN inexistente no leiaute ativo."
                );
            }

            if ($csosn !== IcmsCsosn::CSOSN_102) {
                throw new TaxConfigurationException(
                    "CSOSN {$csosn->value} é conhecido pelo leiaute, mas ainda não é suportado no primeiro cenário do Tax Engine."
                );
            }

            if ((string) $rule->pis_cst !== '49') {
                throw new TaxConfigurationException(
                    "Configuração de PIS CST {$rule->pis_cst} ainda não é suportada no primeiro cenário."
                );
            }

            if ((string) $rule->cofins_cst !== '49') {
                throw new TaxConfigurationException(
                    "Configuração de COFINS CST {$rule->cofins_cst} ainda não é suportada no primeiro cenário."
                );
            }

            if ((string) $rule->pis_base_mode !== self::CONTRIBUTION_BASE_MODE) {
                throw new TaxConfigurationException('Modo de base de PIS não suportado nesta fase.');
            }

            if ((string) $rule->cofins_base_mode !== self::CONTRIBUTION_BASE_MODE) {
                throw new TaxConfigurationException('Modo de base de COFINS não suportado nesta fase.');
            }

            if ((string) $rule->rtc_mode !== RtcMode::REQUIRED->value) {
                throw new TaxConfigurationException(
                    'IBS/CBS deve estar explicitamente configurado como obrigatório para o cenário NFC-e 2026.'
                );
            }

            if ((string) $rule->ibs_cbs_base_mode !== self::RTC_BASE_MODE) {
                throw new TaxConfigurationException(
                    'Modo de base IBS/CBS não corresponde à regra UB16 versionada para 2026.'
                );
            }

            if (
                $rule->ibs_cst === null
                || $rule->ibs_classification === null
                || $rule->ibs_uf_rate === null
                || $rule->ibs_mun_rate === null
                || $rule->cbs_rate === null
            ) {
                throw new TaxConfigurationException(
                    "Produto {$label} possui configuração IBS/CBS incompleta."
                );
            }

            $classification = new IbsCbsClassification(
                (string) $rule->ibs_cst,
                (string) $rule->ibs_classification,
            );
            $this->classifications->validate($classification);

            $ibsUfRate = new TaxRate((string) $rule->ibs_uf_rate);
            $ibsMunRate = new TaxRate((string) $rule->ibs_mun_rate);
            $cbsRate = new TaxRate((string) $rule->cbs_rate);
            Rtc2026Rates::assertConfigured($ibsUfRate, $ibsMunRate, $cbsRate);

            $gross = $itemInput->quantity->multiply($itemInput->unitPrice);
            $operationValue = $gross
                ->subtract($itemInput->discountValue())
                ->add($itemInput->freightValue())
                ->add($itemInput->insuranceValue())
                ->add($itemInput->otherValue());

            $pisRate = new TaxRate((string) $rule->pis_rate);
            $cofinsRate = new TaxRate((string) $rule->cofins_rate);
            $pisAmount = $operationValue->applyRate($pisRate);
            $cofinsAmount = $operationValue->applyRate($cofinsRate);

            // NT 2025.002, regra UB16-10. No cenário suportado ICMS/FCP/ISS/IS são zero.
            $ibsCbsBase = $operationValue
                ->subtract($pisAmount)
                ->subtract($cofinsAmount);

            $ibsUfAmount = $ibsCbsBase->applyRate($ibsUfRate);
            $ibsMunAmount = $ibsCbsBase->applyRate($ibsMunRate);
            $ibsAmount = $ibsUfAmount->add($ibsMunAmount);
            $cbsAmount = $ibsCbsBase->applyRate($cbsRate);

            $tax = new FiscalItemTaxResult(
                icms: new IcmsTaxResult($origin, $csosn),
                pis: new PisTaxResult((string) $rule->pis_cst, $operationValue, $pisRate, $pisAmount),
                cofins: new CofinsTaxResult((string) $rule->cofins_cst, $operationValue, $cofinsRate, $cofinsAmount),
                ibsCbs: new IbsCbsTaxResult(
                    classification: $classification,
                    base: $ibsCbsBase,
                    ibsUfRate: $ibsUfRate,
                    ibsMunRate: $ibsMunRate,
                    cbsRate: $cbsRate,
                    ibsUfAmount: $ibsUfAmount,
                    ibsMunAmount: $ibsMunAmount,
                    ibsAmount: $ibsAmount,
                    cbsAmount: $cbsAmount,
                ),
            );

            // Em 2026, a exceção da regra VB01-10 manda não adicionar IBS/CBS/IS ao vItem.
            $resolvedItems[] = new ResolvedTaxItem(
                input: $itemInput,
                cfop: (string) $rule->cfop,
                gross: $gross,
                pisCofinsBase: $operationValue,
                ibsCbsBase: $ibsCbsBase,
                itemTotal: $operationValue,
                tax: $tax,
            );
        }

        $totals = $this->aggregateTotals($resolvedItems);
        $this->totalsValidator->assertConsistent($resolvedItems, $totals);

        $paid = Money::zero();
        foreach ($input->payments as $payment) {
            $paid = $paid->add($payment->amount);
        }

        if ($paid->compare($totals->nfTotal) < 0) {
            throw new TaxConfigurationException(
                "Somatório dos pagamentos ({$paid->toString()}) é inferior ao total da NFC-e ({$totals->nfTotal->toString()})."
            );
        }

        $change = $paid->subtract($totals->nfTotal);

        return new TaxResult(
            items: $resolvedItems,
            totals: $totals,
            payments: $input->payments,
            change: $change,
        );
    }

    /**
     * @param list<ResolvedTaxItem> $items
     */
    private function aggregateTotals(array $items): TaxDocumentTotals
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
        $vItem = Money::zero();

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
            $vItem = $vItem->add($item->itemTotal);
        }

        $vNf = $products
            ->subtract($discount)
            ->add($freight)
            ->add($insurance)
            ->add($other);

        return new TaxDocumentTotals(
            products: $products,
            freight: $freight,
            insurance: $insurance,
            discount: $discount,
            other: $other,
            pis: $pis,
            cofins: $cofins,
            ibsCbsBase: $ibsCbsBase,
            ibsUf: $ibsUf,
            ibsMun: $ibsMun,
            ibs: $ibs,
            cbs: $cbs,
            nfTotal: $vNf,
            nfTotalWithRtc: $vItem,
        );
    }

    private function productLabel(object $product): string
    {
        return "'".($product->sku ?: $product->name ?: (string) $product->getKey())."'";
    }
}
