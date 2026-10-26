<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\Product;
use App\Models\FiscalTaxGroup;
use App\Models\OperationNature;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Applies reviewed defaults; explicit item values remain visible, auditable overrides. */
final class Nfe55TaxRuleApplicationService
{
    public function __construct(private readonly FiscalTaxRuleResolver $resolver) {}

    public function apply(NfeDraft $draft, ?CompanySetting $company = null, bool $persist = true, bool $allowIncomplete = false): void
    {
        $company ??= CompanySetting::current();
        if (!$persist) {
            $this->applyToItems($draft, $company, false, $allowIncomplete);
            return;
        }
        if (!$draft->exists) throw new RuntimeException('Salve o rascunho antes de persistir regras fiscais.');
        DB::transaction(function () use ($draft, $company, $allowIncomplete) {
            $locked = NfeDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if (!in_array($locked->status, ['draft','validated'], true) || $locked->document_number
                || $locked->access_key || $locked->transmission_attempted_at) {
                throw new RuntimeException('Regras fiscais só podem ser aplicadas antes da reserva ou emissão da NF-e.');
            }
            $locked->load('items', 'customer');
            $this->applyToItems($locked, $company, true, $allowIncomplete);
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
        // Managed catalog items require a fresh determination even before the first trace exists.
        // Ordinary manually classified documents retain their existing behavior.
        if ($traced->isEmpty() && !$this->hasManagedCatalog($draft)) return;
        $copy = clone $draft;
        $copy->setRelation('items', $draft->items->map(static fn ($item) => clone $item));
        $this->applyToItems($copy, $company, false);
        foreach ($draft->items->values() as $index => $original) {
            if (empty(($original->tax_data ?? [])['fiscal_rule_trace'])
                && empty(($copy->items->values()[$index]->tax_data ?? [])['fiscal_rule_trace']['catalog_version'])) continue;
            $before = $original->tax_data['fiscal_rule_trace'] ?? [];
            $after = $copy->items->values()[$index]->tax_data['fiscal_rule_trace'] ?? [];
            if (!empty($before['catalog_version']) && ($before['determination_context'] ?? []) !== ($after['determination_context'] ?? [])) {
                throw new RuntimeException('Item '.$original->item_number.': o contexto da determinação fiscal mudou. Revalide a NF-e antes de emitir.');
            }
            if (!empty($before['catalog_version'])) {
                foreach (['catalog_models','classification_catalog','cfop_reference'] as $proof) {
                    if (($before[$proof] ?? null) !== ($after[$proof] ?? null)) {
                        throw new RuntimeException('Item '.$original->item_number.': a revisão do modelo ou do catálogo fiscal mudou. Revalide a NF-e antes de emitir.');
                    }
                }
            }
            foreach (['id','revision','hash'] as $field) {
                if (!isset($before[$field], $after[$field]) || (string) $before[$field] !== (string) $after[$field]) {
                    throw new RuntimeException('Item '.$original->item_number.': a regra fiscal mudou ou não cobre mais a operação. Revalide a NF-e antes de emitir.');
                }
            }
        }
    }

    private function applyToItems(NfeDraft $draft, CompanySetting $company, bool $persist, bool $allowIncomplete = false): void
    {
        $recipient = $draft->recipient_snapshot ?? [];
        $destination = strtoupper((string) ($recipient['state'] ?? $draft->customer?->state ?? ''));
        if ($draft->destination === 'foreign') $destination = 'EX';
        $date = $draft->issue_date?->format('Y-m-d') ?? now()->toDateString();
        $nature = $draft->operation_nature_id ? OperationNature::query()->find($draft->operation_nature_id) : null;
        $productIds = $draft->items->pluck('product_id')->filter()->unique();
        $products = $productIds->isEmpty() ? collect() : Product::query()->whereKey($productIds)->get()->keyBy('id');
        $groupIds = $products->pluck('fiscal_tax_group_id')->filter()->unique();
        $groups = $groupIds->isEmpty() ? collect() : FiscalTaxGroup::query()->whereKey($groupIds)->get()->keyBy('id');
        foreach ($draft->items as $item) {
            $product = $products->get($item->product_id);
            $group = $product ? $groups->get($product->fiscal_tax_group_id) : null;
            $managed = (bool) ($nature?->catalog_version || $group?->catalog_version);
            $originalAttributes = $item->getAttributes();
            try {
                $tax = is_array($item->tax_data) ? $item->tax_data : [];
                $unappliedTax = $tax;
                $unappliedItem = ['cfop' => $item->cfop, 'fiscal_benefit_code' => $item->fiscal_benefit_code];
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
                unset($tax['fiscal_determination_error']);
                $unappliedTax = $tax;
                unset($unappliedTax['fiscal_rule_trace']);
                $unappliedItem = ['cfop' => $item->cfop, 'fiscal_benefit_code' => $item->fiscal_benefit_code];
                $context = [
                    'product_id' => $item->product_id, 'ncm' => $item->ncm, 'cest' => $item->cest, 'origin' => $item->origin,
                    'issue_date' => $date, 'origin_uf' => strtoupper((string) $company->state), 'destination_uf' => $destination,
                    'crt' => (string) $company->crt, 'tax_regime' => $company->tax_regime,
                    'operation_nature_id' => $draft->operation_nature_id, 'fiscal_tax_group_id' => $product?->fiscal_tax_group_id,
                    'origin_municipality' => $company->city_ibge_code,
                    'destination_municipality' => $recipient['city_ibge_code'] ?? $draft->customer?->city_ibge_code,
                    'recipient_icms_indicator' => match ($recipient['ie_indicator'] ?? $draft->customer?->ie_indicator) {
                        'contributor' => '1', 'exempt' => '2', 'non_contributor' => '9', default => null,
                    },
                    'destination' => $destination === 'EX' ? 'foreign' : ($destination === strtoupper((string) $company->state) ? 'internal' : 'interstate'),
                    'product_usage_type' => $product?->usage_type,
                    'operation_type' => $draft->operation_type, 'purpose' => $draft->purpose,
                    'fiscal_operation' => data_get($draft->custom_fields, 'fiscal_operation', $draft->purpose === 'return' ? 'devolution' : 'sale'),
                    'recipient_profile' => $recipient['ie_indicator'] ?? $draft->customer?->ie_indicator,
                    'final_consumer' => $draft->final_consumer,
                ];
                if ($managed) {
                    if (!InstanceIdentity::matches($company->document)) throw new RuntimeException('O emitente da determinação fiscal pertence a outra instalação/CNPJ.');
                    foreach ([$nature, $group] as $model) $this->assertCatalogModel($model, $context, $date);
                    app(FiscalProductClassificationService::class)->assertForEmission($item->toArray(), $date, (string) $company->state);
                }
                $inputContext = ['cfop' => $item->cfop, 'fiscal_benefit_code' => $item->fiscal_benefit_code];
                $rule = $this->resolver->resolve('nfe', strtoupper((string) $company->state), $destination,
                    (string) $company->crt, [
                        ...$context,
                        'product_id' => $item->product_id, 'ncm' => $item->ncm,
                        'cfop' => $item->cfop, 'cest' => $item->cest, 'origin' => $item->origin,
                        'operation_type' => $draft->operation_type, 'purpose' => $draft->purpose,
                        'fiscal_operation' => data_get($draft->custom_fields, 'fiscal_operation', $draft->purpose === 'return' ? 'devolution' : 'sale'),
                        'recipient_profile' => $recipient['ie_indicator'] ?? $draft->customer?->ie_indicator,
                        'tax_regime' => $company->tax_regime, 'final_consumer' => $draft->final_consumer,
                    ], $date);
                unset($tax['fiscal_rule_trace']);
                if ($managed && !$rule) throw new RuntimeException('Item '.$item->item_number.': nenhuma regra fiscal revisada cobre a natureza, grupo tributário, destinatário e vigência desta operação.');
                if ($managed && $rule && (!$rule->catalog_version || !in_array($rule->catalog_status, ['reviewed','custom'], true) || !$rule->fiscal_reviewed_at)) {
                    throw new RuntimeException('Item '.$item->item_number.': revise e habilite a regra fiscal para aplicação automática do catálogo.');
                }
                $cfopReference = $managed && $rule ? $this->assertCatalogRule($rule, $context, $date) : null;
                if ($rule) {
                    $groupDefaults = $managed ? $this->reviewedGroupDefaults($group, (string) $draft->operation_type) : [];
                    $defaults = array_replace($groupDefaults, $rule->tax_parameters ?? [], array_filter([
                        'icms_cst' => $rule->icms_cst, 'icms_csosn' => $rule->csosn,
                        'pis_cst' => $rule->pis_cst, 'cofins_cst' => $rule->cofins_cst,
                    ], static fn ($value) => $value !== null && $value !== ''));
                    unset($defaults['fiscal_benefit_code']);
                    $overrides = []; $appliedDefaults = []; $appliedItemDefaults = [];
                    foreach ($defaults as $field => $value) {
                        if (!array_key_exists($field, $tax) || $tax[$field] === null || $tax[$field] === '') {
                            $tax[$field] = $value;
                            $appliedDefaults[$field] = $value;
                        } elseif (!$this->sameValue($field, $tax[$field], $value)) {
                            if ($managed && $this->treatmentControl($field)) throw new RuntimeException('Item '.$item->item_number.': o parâmetro '.$field.' difere da regra fiscal revisada. Corrija o item ou cadastre uma exceção fiscal revisada.');
                            $overrides[$field] = ['rule' => $value, 'item' => $tax[$field]];
                        }
                    }
                    $itemDefaults = ['cfop' => $rule->cfop, 'fiscal_benefit_code' => $rule->fiscal_benefit_code ?: ($groupDefaults['fiscal_benefit_code'] ?? null)];
                    foreach ($itemDefaults as $field => $value) {
                        if ($value === null || $value === '') continue;
                        if (($managed && $field === 'cfop') || $item->{$field} === null || $item->{$field} === '') {
                            $item->{$field} = $value;
                            $appliedItemDefaults[$field] = $value;
                        }
                        elseif ((string) $item->{$field} !== (string) $value) {
                            if ($managed) throw new RuntimeException('Item '.$item->item_number.': o benefício fiscal difere da regra revisada. Cadastre uma exceção fiscal revisada.');
                            $overrides[$field] = ['rule' => $value, 'item' => $item->{$field}];
                        }
                    }
                    if ($managed) $this->assertReviewedControls($tax, $defaults, $group, $item->fiscal_benefit_code, $rule->fiscal_benefit_code, (string) $draft->operation_type);
                    $tax['fiscal_rule_trace'] = [...$rule->trace(), 'overrides' => $overrides,
                        'applied_defaults' => $appliedDefaults, 'applied_item_defaults' => $appliedItemDefaults,
                        'input_context' => $inputContext,
                        'determination_context' => $context, 'catalog_version' => $rule->catalog_version,
                        'resolved_codes' => ['cfop' => $item->cfop, 'csosn' => $rule->csosn, 'icms_cst' => $rule->icms_cst,
                            'pis_cst' => $rule->pis_cst, 'cofins_cst' => $rule->cofins_cst]];
                }
                if ($managed && $rule) {
                    $tax['fiscal_rule_trace']['classification_catalog'] = app(FiscalProductClassificationService::class)->trace($item->toArray(), $date, (string) $company->state);
                    $tax['fiscal_rule_trace']['cfop_reference'] = $cfopReference;
                    $tax['fiscal_rule_trace']['catalog_models'] = ['nature' => $this->modelTrace($nature), 'group' => $this->modelTrace($group)];
                }
                $item->tax_data = $tax;
                if ($persist && $item->isDirty()) $item->save();
            } catch (RuntimeException $error) {
                if (!$allowIncomplete || !$managed) throw $error;
                $item->setRawAttributes($originalAttributes);
                $item->cfop = $unappliedItem['cfop'];
                $item->fiscal_benefit_code = $unappliedItem['fiscal_benefit_code'];
                $item->tax_data = [...$unappliedTax, 'fiscal_determination_error' => $error->getMessage()];
                if ($persist && $item->isDirty()) $item->save();
            }
        }
    }

    private function hasManagedCatalog(NfeDraft $draft): bool
    {
        if ($draft->operation_nature_id && OperationNature::query()->whereKey($draft->operation_nature_id)->whereNotNull('catalog_version')->exists()) return true;
        $productIds = $draft->items->pluck('product_id')->filter();
        if ($productIds->isEmpty()) return false;
        $groupIds = Product::query()->whereKey($productIds)->pluck('fiscal_tax_group_id')->filter();
        return $groupIds->isNotEmpty() && FiscalTaxGroup::query()->whereKey($groupIds)->whereNotNull('catalog_version')->exists();
    }

    private function assertCatalogModel(?\Illuminate\Database\Eloquent\Model $model, array $context, string $date): void
    {
        if (!$model?->catalog_version) return;
        if (!$model->is_active || !in_array($model->catalog_status, ['reviewed','custom'], true) || !$model->fiscal_reviewed_at) {
            throw new RuntimeException('O modelo fiscal '.$model->name.' precisa ser revisado e habilitado antes da determinação automática.');
        }
        if ($model instanceof FiscalTaxGroup && (in_array($context['crt'], ['1','4'], true)
            ? (bool) $model->icms_cst : (bool) $model->icms_csosn)) {
            throw new RuntimeException('O grupo fiscal '.$model->name.' possui classificação de ICMS incompatível com o CRT do emitente.');
        }
        if ($model instanceof FiscalTaxGroup && $model->target_crt !== null && (string) $model->target_crt !== $context['crt']) {
            throw new RuntimeException('O grupo fiscal '.$model->name.' exige o CRT '.$model->target_crt.'.');
        }
        if ($model instanceof OperationNature && ($model->operation_type !== $context['operation_type'] || $model->purpose !== $context['purpose'])) {
            throw new RuntimeException('A natureza fiscal '.$model->name.' não corresponde à direção ou finalidade da NF-e.');
        }
        $metadata = $model->catalog_metadata ?? [];
        $validity = $metadata['review'] ?? $metadata;
        if ((!empty($validity['valid_from']) && $date < $validity['valid_from'])
            || (!empty($validity['valid_until']) && $date > $validity['valid_until'])) {
            throw new RuntimeException('O modelo fiscal '.$model->name.' está fora da vigência.');
        }
        foreach (($metadata['required_context'] ?? []) as $field => $allowed) {
            if (!array_key_exists($field, $context)) throw new RuntimeException('Critério fiscal do catálogo ainda não suportado: '.$field.'.');
            $values = is_array($allowed) ? $allowed : [$allowed];
            if (!in_array($context[$field], $values, true)) throw new RuntimeException('O modelo fiscal '.$model->name.' não cobre o critério '.$field.' desta operação.');
        }
    }

    private function assertCatalogRule(\App\Models\FiscalTaxRule $rule, array $context, string $date): array
    {
        $catalog = app(FiscalCatalogService::class);
        $reference = $catalog->current() ? $catalog->entries('cfop', $date, $rule->origin_uf)->where('code', $rule->cfop)->first() : null;
        if (!$reference) {
            throw new RuntimeException('O CFOP da regra não está disponível no catálogo oficial instalado para esta vigência.');
        }
        $codes = \App\Support\FiscalCodeCatalog::all();
        foreach (['csosn' => 'csosn', 'icms_cst' => 'icms_cst', 'pis_cst' => 'pis_cofins_cst', 'cofins_cst' => 'pis_cofins_cst'] as $field => $kind) {
            if ($rule->{$field} !== null && $rule->{$field} !== '' && !array_key_exists((string) $rule->{$field}, $codes[$kind])) {
                throw new RuntimeException('Código tributário não reconhecido para aplicação automática: '.$field.'.');
            }
        }
        $prefix = substr((string) $rule->cfop, 0, 1);
        $direction = $context['operation_type'] === 'inbound' ? ['1','2','3'] : ['5','6','7'];
        $geography = match ($context['destination']) { 'internal' => ['1','5'], 'interstate' => ['2','6'], default => ['3','7'] };
        if (!in_array($prefix, $direction, true) || !in_array($prefix, $geography, true)) throw new RuntimeException('O CFOP da regra fiscal não corresponde à direção e ao destino desta operação.');
        if (in_array($rule->cfop, ['6101','6102'], true) && !in_array($context['recipient_profile'], ['contributor','exempt'], true)) throw new RuntimeException('Este CFOP de venda interestadual exige destinatário contribuinte. Revise a regra específica do destinatário.');
        if (in_array($rule->cfop, ['6107','6108'], true) && $context['recipient_profile'] !== 'non_contributor') throw new RuntimeException('Este CFOP de venda interestadual exige destinatário não contribuinte.');
        $crt = (string) $rule->crt;
        if (in_array($crt, ['1','4'], true) && (!$rule->csosn || $rule->icms_cst)) throw new RuntimeException('A regra do Simples Nacional deve determinar CSOSN, sem CST ICMS.');
        if (in_array($crt, ['2','3'], true) && (!$rule->icms_cst || $rule->csosn)) throw new RuntimeException('A regra do regime normal/excesso de sublimite deve determinar CST ICMS, sem CSOSN.');
        return $reference->trace();
    }

    private function modelTrace(?\Illuminate\Database\Eloquent\Model $model): ?array
    {
        if (!$model) return null;
        $snapshot = $model->only($model->getFillable());
        $snapshot['fiscal_reviewed_at'] = $model->fiscal_reviewed_at?->toIso8601String();
        return ['id' => $model->id, 'revision' => $model->revision, 'catalog_version' => $model->catalog_version,
            'snapshot' => $snapshot, 'hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    /** Bases, quantities and historic retained amounts belong to the actual transaction. */
    private function treatmentControl(string $field): bool
    {
        return (bool) preg_match('/(?:_cst|_csosn|_rate|_reduction|_base_mode|_calculation_type|_enq|_reason|_deduct|_add_to_total|_amount_scope|_valid_from|_valid_until|_legal_source|_legal_reference|_legal_basis)$/D', $field)
            || in_array($field, ['icms_st_mva','ibs_cbs_class','is_class','rtc_regular_class','is_unit'], true);
    }

    /** Only canonical parameters understood by this NF-e motor can become item defaults. */
    private function reviewedGroupDefaults(?FiscalTaxGroup $group, string $direction): array
    {
        if (!$group?->catalog_version || !in_array($group->catalog_status, ['reviewed','custom'], true) || !$group->fiscal_reviewed_at) return [];
        $raw = $group->tax_config ?? [];
        foreach (['credit_rate' => 'icms_credit_rate', 'pis_quantity_rate' => 'pis_unit_rate',
            'cofins_quantity_rate' => 'cofins_unit_rate', 'pis_st_calc_type' => 'pis_st_calculation_type',
            'cofins_st_calc_type' => 'cofins_st_calculation_type'] as $legacy => $canonical) {
            if ((!isset($raw[$canonical]) || $raw[$canonical] === '') && isset($raw[$legacy]) && $raw[$legacy] !== '') $raw[$canonical] = $raw[$legacy];
        }
        foreach (['pis_st_calculation_type','cofins_st_calculation_type'] as $field) {
            if (($raw[$field] ?? '') === 'percentage') $raw[$field] = 'percent';
        }
        $canonical = Nfe55TaxParameters::forDirection($raw, $direction);
        $defaults = array_intersect_key($canonical, array_flip([...Nfe55TaxParameters::RULE_PARAMETERS, 'fiscal_benefit_code']));
        $defaults = array_replace($defaults, array_filter([
            'icms_csosn' => $group->icms_csosn, 'icms_cst' => $group->icms_cst,
            'pis_cst' => $group->pis_cst, 'cofins_cst' => $group->cofins_cst, 'ipi_cst' => $group->ipi_cst,
        ], static fn ($value) => $value !== null && $value !== ''));
        return array_filter($defaults, static fn ($value) => is_scalar($value) && $value !== '');
    }

    private function assertReviewedControls(array $tax, array $defaults, ?FiscalTaxGroup $group, mixed $benefit, mixed $ruleBenefit, string $direction): void
    {
        $groupDefaults = $this->reviewedGroupDefaults($group, $direction);
        // These aliases have already been normalized by forDirection; checking both would
        // incorrectly reject an explicit, approved value stored in the legacy field name.
        $aliases = ['base_reduction_rate','simple_credit_rate','mva_rate','icms_deferral_rate',
            'interstate_icms_rate','internal_icms_rate','fcp_interstate_rate','ibs_cst','cbs_cst'];
        foreach ($tax as $field => $value) {
            if ($value === null || $value === '' || in_array($field, $aliases, true)
                || str_ends_with($field, '_inbound') || str_ends_with($field, '_export') || !$this->treatmentControl($field)) continue;
            if (array_key_exists($field, $defaults) && $this->sameValue($field, $value, $defaults[$field])) continue;
            if (!in_array($field, ['icms_cst','icms_csosn'], true)
                && array_key_exists($field, $groupDefaults) && $this->sameValue($field, $value, $groupDefaults[$field])) continue;
            throw new RuntimeException('O parâmetro '.$field.' não consta na regra ou no grupo fiscal revisado. Cadastre uma exceção fiscal revisada.');
        }
        if ($benefit !== null && $benefit !== '' && (string) $benefit !== (string) $ruleBenefit
            && (string) $benefit !== (string) ($groupDefaults['fiscal_benefit_code'] ?? '')) {
            throw new RuntimeException('O benefício fiscal do item não consta na regra ou no grupo fiscal revisado.');
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
