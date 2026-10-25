<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Applies reviewed defaults; explicit item values remain visible, auditable overrides. */
final class Nfe55TaxRuleApplicationService
{
    public function __construct(private readonly FiscalTaxRuleResolver $resolver) {}

    public function apply(NfeDraft $draft, ?CompanySetting $company = null, bool $persist = true): void
    {
        $company ??= CompanySetting::current();
        if (!$persist) {
            $this->applyToItems($draft, $company, false);
            return;
        }
        if (!$draft->exists) throw new RuntimeException('Salve o rascunho antes de persistir regras fiscais.');
        DB::transaction(function () use ($draft, $company) {
            $locked = NfeDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if (!in_array($locked->status, ['draft','validated'], true) || $locked->document_number
                || $locked->access_key || $locked->transmission_attempted_at) {
                throw new RuntimeException('Regras fiscais só podem ser aplicadas antes da reserva ou emissão da NF-e.');
            }
            $locked->load('items', 'customer');
            $this->applyToItems($locked, $company, true);
            // Any mutation must be validated again before numbering.
            if ($locked->items->contains(fn ($item) => $item->wasChanged())) {
                $locked->update(['validated_at' => null, 'status' => 'draft']);
            }
            $draft->setRelation('items', $locked->items);
        }, 3);
    }

    /** A reviewed snapshot cannot silently outlive its source revision or coverage. */
    public function assertCurrent(NfeDraft $draft, CompanySetting $company): void
    {
        $draft->loadMissing('items');
        $traced = $draft->items->filter(fn ($item) => !empty(($item->tax_data ?? [])['fiscal_rule_trace']));
        // Ordinary manually classified documents do not depend on the rule repository.
        if ($traced->isEmpty()) return;
        $copy = clone $draft;
        $copy->setRelation('items', $traced->map(static fn ($item) => clone $item));
        $this->applyToItems($copy, $company, false);
        foreach ($traced->values() as $index => $original) {
            $before = $original->tax_data['fiscal_rule_trace'] ?? [];
            $after = $copy->items->values()[$index]->tax_data['fiscal_rule_trace'] ?? [];
            foreach (['id','revision','hash'] as $field) {
                if (!isset($before[$field], $after[$field]) || (string) $before[$field] !== (string) $after[$field]) {
                    throw new RuntimeException('Item '.$original->item_number.': a regra fiscal mudou ou não cobre mais a operação. Revalide a NF-e antes de emitir.');
                }
            }
        }
    }

    private function applyToItems(NfeDraft $draft, CompanySetting $company, bool $persist): void
    {
        $recipient = $draft->recipient_snapshot ?? [];
        $destination = strtoupper((string) ($recipient['state'] ?? $draft->customer?->state ?? ''));
        if ($draft->destination === 'foreign') $destination = 'EX';
        $date = $draft->issue_date?->format('Y-m-d') ?? now()->toDateString();
        foreach ($draft->items as $item) {
            $tax = is_array($item->tax_data) ? $item->tax_data : [];
            $previous = is_array($tax['fiscal_rule_trace'] ?? null) ? $tax['fiscal_rule_trace'] : [];
            // Only values demonstrably inherited from the previous rule may refresh automatically.
            // A different current value is a manual override and must survive revalidation.
            foreach (($previous['applied_defaults'] ?? []) as $field => $oldValue) {
                if (array_key_exists($field, $tax) && $this->sameValue($field, $tax[$field], $oldValue)) unset($tax[$field]);
            }
            foreach (($previous['applied_item_defaults'] ?? []) as $field => $oldValue) {
                if (in_array($field, ['cfop','fiscal_benefit_code'], true)
                    && $this->sameValue($field, $item->{$field}, $oldValue)) {
                    $item->{$field} = $previous['input_context'][$field] ?? null;
                }
            }
            // Inbound aliases are explicit product/editor choices too; audit the values
            // that will actually reach the calculators, rather than only outbound defaults.
            $tax = Nfe55TaxParameters::forDirection($tax, (string) $draft->operation_type);
            $legacyIbs = trim((string) ($tax['ibs_cst'] ?? ''));
            $legacyCbs = trim((string) ($tax['cbs_cst'] ?? ''));
            if ($legacyIbs !== '' && $legacyIbs === $legacyCbs) $tax['ibs_cbs_cst'] = $legacyIbs;
            if (trim((string) ($tax['tax_classification_code'] ?? '')) !== '') $tax['ibs_cbs_class'] = $tax['tax_classification_code'];
            $inputContext = ['cfop' => $item->cfop, 'fiscal_benefit_code' => $item->fiscal_benefit_code];
            $rule = $this->resolver->resolve('nfe', strtoupper((string) $company->state), $destination,
                (string) $company->crt, [
                    'product_id' => $item->product_id, 'ncm' => $item->ncm,
                    'cfop' => $item->cfop, 'cest' => $item->cest, 'origin' => $item->origin,
                    'operation_type' => $draft->operation_type, 'purpose' => $draft->purpose,
                    'fiscal_operation' => data_get($draft->custom_fields, 'fiscal_operation', $draft->purpose === 'return' ? 'devolution' : 'sale'),
                    'recipient_profile' => $recipient['ie_indicator'] ?? $draft->customer?->ie_indicator,
                    'tax_regime' => $company->tax_regime, 'final_consumer' => $draft->final_consumer,
                ], $date);
            unset($tax['fiscal_rule_trace']);
            if ($rule) {
                $defaults = array_replace($rule->tax_parameters ?? [], array_filter([
                    'icms_cst' => $rule->icms_cst, 'icms_csosn' => $rule->csosn,
                    'pis_cst' => $rule->pis_cst, 'cofins_cst' => $rule->cofins_cst,
                ], static fn ($value) => $value !== null && $value !== ''));
                $overrides = []; $appliedDefaults = []; $appliedItemDefaults = [];
                foreach ($defaults as $field => $value) {
                    if (!array_key_exists($field, $tax) || $tax[$field] === null || $tax[$field] === '') {
                        $tax[$field] = $value;
                        $appliedDefaults[$field] = $value;
                    } elseif (!$this->sameValue($field, $tax[$field], $value)) {
                        $overrides[$field] = ['rule' => $value, 'item' => $tax[$field]];
                    }
                }
                foreach (['cfop', 'fiscal_benefit_code'] as $field) {
                    if (!$rule->{$field}) continue;
                    if ($item->{$field} === null || $item->{$field} === '') {
                        $item->{$field} = $rule->{$field};
                        $appliedItemDefaults[$field] = $rule->{$field};
                    }
                    elseif ((string) $item->{$field} !== (string) $rule->{$field}) {
                        $overrides[$field] = ['rule' => $rule->{$field}, 'item' => $item->{$field}];
                    }
                }
                $tax['fiscal_rule_trace'] = [...$rule->trace(), 'overrides' => $overrides,
                    'applied_defaults' => $appliedDefaults, 'applied_item_defaults' => $appliedItemDefaults,
                    'input_context' => $inputContext];
            }
            $item->tax_data = $tax;
            if ($persist && $item->isDirty()) $item->save();
        }
    }

    private function sameValue(string $field, mixed $a, mixed $b): bool
    {
        if ((!is_scalar($a) && $a !== null) || (!is_scalar($b) && $b !== null)) return false;
        if (str_ends_with($field, '_cst') || $field === 'ibs_cbs_class' || str_ends_with($field, '_csosn')
            || str_ends_with($field, '_enq') || in_array($field, ['cfop','fiscal_benefit_code'], true)) return (string) $a === (string) $b;
        $normalize = static function (mixed $value): string {
            $text = (string) $value;
            if (!preg_match('/^\d+(?:\.\d+)?$/D', $text)) return $text;
            [$integer, $decimal] = array_pad(explode('.', $text, 2), 2, '');
            return (ltrim($integer, '0') ?: '0').'.'.rtrim($decimal, '0');
        };
        return $normalize($a) === $normalize($b);
    }
}
