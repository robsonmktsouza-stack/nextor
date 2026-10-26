<?php

namespace App\Services\Fiscal;

use App\Models\FiscalTaxRule;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Resolve a classificação tributária aprovada; não infere alíquotas nem
 * transforma NCM sozinho em um enquadramento fiscal.
 */
final class FiscalTaxRuleResolver
{
    public function resolve(
        string $documentType,
        string $originUf,
        string $destinationUf,
        string $crt,
        array $item,
        ?string $date = null,
    ): ?FiscalTaxRule {
        $day = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
        if ($date && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $day !== $date)) {
            throw new \InvalidArgumentException('Data de vigência inválida.');
        }
        $ncm = preg_replace('/\D/', '', (string) ($item['ncm'] ?? ''));
        $productId = (int) ($item['product_id'] ?? 0);

        $candidates = FiscalTaxRule::query()
            ->where('document_type', $documentType)
            ->where('origin_uf', strtoupper($originUf))
            ->where('destination_uf', strtoupper($destinationUf))
            ->where('crt', $crt)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('catalog_status')->orWhereIn('catalog_status', ['reviewed','custom']))
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day))
            ->get()
            ->filter(function (FiscalTaxRule $rule) use ($productId, $ncm) {
                if ($rule->product_id !== null && $rule->product_id !== $productId) {
                    return false;
                }

                return !$rule->ncm_prefix || str_starts_with($ncm, $rule->ncm_prefix);
            })
            ->filter(function (FiscalTaxRule $rule) use ($item) {
                foreach (self::CONTEXT_FIELDS as $field => $contextField) {
                    if ($rule->{$field} !== null && $rule->{$field} !== '') {
                        if (!array_key_exists($contextField, $item) || $item[$contextField] === null) return false;
                        $actual = (string) ($item[$contextField] ?? '');
                        if ($field === 'cest') $actual = preg_replace('/\D/', '', $actual);
                        if ((string) $rule->{$field} !== $actual) return false;
                    }
                }
                return true;
            })
            ->sort(fn (FiscalTaxRule $a, FiscalTaxRule $b) => self::rank($b) <=> self::rank($a))
            ->values();

        if ($candidates->count() > 1
            && self::rank($candidates[0]) === self::rank($candidates[1])) {
            throw new RuntimeException('Regras fiscais conflitantes: '.$candidates[0]->name.' e '.$candidates[1]->name.'. Ajuste os critérios ou a prioridade.');
        }

        $winner = $candidates->first();
        if ($winner && $documentType === 'nfe'
            && (!$winner->legal_source || !$winner->legal_reference || !$winner->layout_version
                || !$winner->consulted_at || !$winner->valid_from)) {
            throw new RuntimeException('A regra NF-e aplicável está sem fonte normativa, versão, consulta ou início de vigência.');
        }
        if ($winner && $documentType === 'nfe' && $winner->catalog_version && !$winner->fiscal_reviewed_at) {
            throw new RuntimeException('A regra do catálogo fiscal aplicável ainda não foi revisada por usuário autorizado.');
        }
        return $winner;
    }

    public const CONTEXT_FIELDS = [
        'input_cfop' => 'cfop', 'cest' => 'cest', 'origin' => 'origin',
        'operation_type' => 'operation_type', 'purpose' => 'purpose',
        'fiscal_operation' => 'fiscal_operation',
        'recipient_profile' => 'recipient_profile', 'tax_regime' => 'tax_regime',
        'final_consumer' => 'final_consumer',
        'operation_nature_id' => 'operation_nature_id', 'fiscal_tax_group_id' => 'fiscal_tax_group_id',
        'origin_municipality' => 'origin_municipality', 'destination_municipality' => 'destination_municipality',
        'recipient_icms_indicator' => 'recipient_icms_indicator',
    ];

    /** NFC-e preserves its existing product/NCM precedence. NF-e uses explicit priority first. */
    public static function rank(FiscalTaxRule $rule): array
    {
        $specific = count(array_filter(array_keys(self::CONTEXT_FIELDS),
            fn ($field) => $rule->{$field} !== null && $rule->{$field} !== ''));
        $criteria = [(int) (bool) $rule->product_id, strlen((string) $rule->ncm_prefix), $specific];
        return $rule->document_type === 'nfe'
            ? [$rule->priority, ...$criteria] : [...$criteria, $rule->priority];
    }

    /** Overlap at the same rank is rejected both during editing and at emission. */
    public function conflicts(FiscalTaxRule $candidate): array
    {
        if (!$candidate->is_active || $candidate->catalog_status === 'template') return [];
        return FiscalTaxRule::query()->where('document_type', $candidate->document_type)
            ->where('origin_uf', $candidate->origin_uf)->where('destination_uf', $candidate->destination_uf)
            ->where('crt', $candidate->crt)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('catalog_status')->orWhereIn('catalog_status', ['reviewed','custom']))->get()
            ->filter(function (FiscalTaxRule $other) use ($candidate) {
                if ($other->id === $candidate->id || self::rank($other) !== self::rank($candidate)) return false;
                if ($other->valid_until && $candidate->valid_from && $other->valid_until < $candidate->valid_from) return false;
                if ($candidate->valid_until && $other->valid_from && $candidate->valid_until < $other->valid_from) return false;
                if ($candidate->product_id && $other->product_id && $candidate->product_id !== $other->product_id) return false;
                $a = (string) $candidate->ncm_prefix; $b = (string) $other->ncm_prefix;
                if ($a && $b && !str_starts_with($a, $b) && !str_starts_with($b, $a)) return false;
                foreach (array_keys(self::CONTEXT_FIELDS) as $field) {
                    if ($candidate->{$field} !== null && $candidate->{$field} !== ''
                        && $other->{$field} !== null && $other->{$field} !== ''
                        && (string) $candidate->{$field} !== (string) $other->{$field}) return false;
                }
                return true;
            })->pluck('name')->all();
    }
}
