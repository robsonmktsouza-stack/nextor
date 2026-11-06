<?php

namespace App\Services\Fiscal;

use App\Models\FiscalCatalogRelease;

/** Completeness is a statement about a particular domain, territory and date. */
final class FiscalCatalogCoverage
{
    public const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB',
        'PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    public static function scope(?FiscalCatalogRelease $release, string $kind, ?string $uf = null, ?string $date = null): array
    {
        if ($date !== null) {
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (!$parsed || $parsed->format('Y-m-d') !== $date) return ['status'=>'not_bundled',
                'complete'=>false, 'uf'=>$uf, 'scope'=>'UF', 'reason'=>'invalid_date'];
        }
        if ($release === null) return ['status' => 'not_bundled', 'complete' => false,
            'uf' => $uf, 'scope' => 'UF', 'reason' => 'catalog_not_installed'];
        $raw = $release->coverage[$kind] ?? 'not_bundled';
        $coverage = is_array($raw) ? $raw : ['status' => $raw];
        $uf = $uf === null ? null : strtoupper(trim($uf));
        $byUf = is_array($coverage['by_uf'] ?? null) ? $coverage['by_uf'] : [];
        $declaredUf = $coverage['uf'] ?? null;
        $national = $kind !== 'benefit' && (($coverage['scope'] ?? null) === 'BR'
            || (!isset($coverage['scope']) && !$byUf && $declaredUf === null));
        if ($uf !== null && !in_array($uf, self::UFS, true)) {
            return ['status' => 'not_bundled', 'complete' => false, 'uf' => $uf, 'scope' => 'UF', 'reason' => 'invalid_uf'];
        }
        if ($uf !== null && array_key_exists($uf, $byUf)) {
            $selected = is_array($byUf[$uf]) ? $byUf[$uf] : ['status' => $byUf[$uf]];
            $selected = array_replace($coverage, $selected);
        } elseif ($uf !== null && !$national && $declaredUf !== $uf) {
            return ['status' => 'not_bundled', 'complete' => false, 'uf' => $uf, 'scope' => 'UF',
                'reason' => 'uf_not_covered', 'snapshot_date' => $coverage['snapshot_date'] ?? null];
        } else {
            $selected = $coverage;
        }
        unset($selected['by_uf']);
        $status = $selected['status'] ?? 'not_bundled';
        if ($uf === null && !$national && $status === 'complete') {
            // A few complete state tables never establish national completeness.
            $allStates = count($byUf) === count(self::UFS);
            foreach (self::UFS as $state) {
                $stateCoverage = $byUf[$state] ?? null;
                $stateStatus = is_array($stateCoverage) ? ($stateCoverage['status'] ?? null) : $stateCoverage;
                $allStates = $allStates && $stateStatus === 'complete';
            }
            if (!$allStates) $status = 'partial';
        }
        $snapshot = $selected['snapshot_date'] ?? null;
        $historical = $date !== null && is_string($snapshot) && $date < $snapshot;
        $complete = $status === 'complete' && !$historical;
        return array_replace($selected, ['status' => $historical && $status === 'complete' ? 'partial' : $status,
            'complete' => $complete, 'uf' => $uf, 'scope' => $national ? 'BR' : 'UF',
            'reason' => $historical ? 'before_snapshot' : null]);
    }

    public static function national(FiscalCatalogRelease $release, string $kind, ?string $date = null): bool
    {
        $coverage = self::scope($release, $kind, null, $date);
        return $coverage['scope'] === 'BR' && $coverage['complete'];
    }
}
