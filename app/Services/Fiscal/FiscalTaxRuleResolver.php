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
        $ncm = preg_replace('/\D/', '', (string) ($item['ncm'] ?? ''));
        $productId = (int) ($item['product_id'] ?? 0);

        $candidates = FiscalTaxRule::query()
            ->where('document_type', $documentType)
            ->where('origin_uf', strtoupper($originUf))
            ->where('destination_uf', strtoupper($destinationUf))
            ->where('crt', $crt)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day))
            ->get()
            ->filter(function (FiscalTaxRule $rule) use ($productId, $ncm) {
                if ($rule->product_id !== null && $rule->product_id !== $productId) {
                    return false;
                }

                return !$rule->ncm_prefix || str_starts_with($ncm, $rule->ncm_prefix);
            })
            ->map(function (FiscalTaxRule $rule) {
                $rule->match_score = ($rule->product_id ? 1000 : 0)
                    + strlen((string) $rule->ncm_prefix) * 10
                    + $rule->priority;
                return $rule;
            })
            ->sortByDesc('match_score')
            ->values();

        if ($candidates->count() > 1
            && $candidates[0]->match_score === $candidates[1]->match_score) {
            throw new RuntimeException('Regras fiscais conflitantes: '.$candidates[0]->name.' e '.$candidates[1]->name.'. Ajuste os critérios ou a prioridade.');
        }

        return $candidates->first();
    }
}
