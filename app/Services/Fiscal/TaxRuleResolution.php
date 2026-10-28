<?php

namespace App\Services\Fiscal;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves approved fiscal rules without silently guessing when matches conflict.
 * Input rules must come from a reviewed, versioned fiscal rule dataset.
 * This component never infers tax liability from NCM alone.
 */
final class TaxRuleResolution
{
    /**
     * @param list<array<string,mixed>> $rules
     * @param array<string,string|int|bool|null> $context
     * @return array<string,mixed>
     */
    public function resolve(array $rules, array $context, string $date): array
    {
        $when = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$when || $when->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid effective date.');
        }

        $matches = [];
        foreach ($rules as $rule) {
            if (($rule['is_active'] ?? false) !== true) {
                continue;
            }
            if (($rule['valid_from'] ?? null) && $date < $rule['valid_from']) {
                continue;
            }
            if (($rule['valid_until'] ?? null) && $date > $rule['valid_until']) {
                continue;
            }
            $criteria = $rule['criteria'] ?? [];
            if (!is_array($criteria)) {
                throw new InvalidArgumentException('Rule criteria must be an array.');
            }
            foreach ($criteria as $field => $expected) {
                if (!array_key_exists($field, $context) || (string) $context[$field] !== (string) $expected) {
                    continue 2;
                }
            }
            if (!isset($rule['legal_source'], $rule['revision'], $rule['result'])
                || !is_string($rule['legal_source']) || trim($rule['legal_source']) === ''
                || !is_array($rule['result'])) {
                throw new RuntimeException('Applicable fiscal rule lacks normative traceability.');
            }
            $matches[] = $rule;
        }
        if (!$matches) {
            throw new RuntimeException('No approved fiscal rule covers this operation.');
        }
        usort($matches, static function (array $a, array $b): int {
            return [(int)($b['priority'] ?? 0), count($b['criteria'] ?? [])]
                <=> [(int)($a['priority'] ?? 0), count($a['criteria'] ?? [])];
        });
        $winner = $matches[0];
        $rank = [(int)($winner['priority'] ?? 0), count($winner['criteria'] ?? [])];
        foreach (array_slice($matches, 1) as $candidate) {
            if ([(int)($candidate['priority'] ?? 0), count($candidate['criteria'] ?? [])] !== $rank) {
                break;
            }
            if ($candidate['result'] !== $winner['result']) {
                throw new RuntimeException('Conflicting fiscal rules have equal precedence.');
            }
        }
        return $winner;
    }
}
