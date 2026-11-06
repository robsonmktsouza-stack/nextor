<?php

namespace Tests\Unit;

use App\Services\Fiscal\TaxRuleResolution;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TaxRuleResolutionTest extends TestCase
{
    private function rule(array $overrides = []): array
    {
        return array_replace([
            'is_active' => true,
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'criteria' => ['origin_uf' => 'SP'],
            'priority' => 10,
            'revision' => 1,
            'legal_source' => 'https://www.confaz.fazenda.gov.br/',
            'result' => ['tax_status' => 'approved'],
        ], $overrides);
    }

    public function test_specific_rule_wins_at_equal_priority(): void
    {
        $rules = [
            $this->rule(),
            $this->rule(['criteria' => ['origin_uf' => 'SP', 'destination_uf' => 'RJ'],
                'result' => ['tax_status' => 'interstate']]),
        ];
        $winner = (new TaxRuleResolution())->resolve(
            $rules, ['origin_uf' => 'SP', 'destination_uf' => 'RJ'], '2026-10-25'
        );
        $this->assertSame('interstate', $winner['result']['tax_status']);
    }

    public function test_expired_rule_is_not_applied(): void
    {
        $this->expectException(RuntimeException::class);
        (new TaxRuleResolution())->resolve(
            [$this->rule(['valid_until' => '2026-01-31'])],
            ['origin_uf' => 'SP'], '2026-10-25'
        );
    }

    public function test_conflicting_equally_ranked_rules_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new TaxRuleResolution())->resolve([
            $this->rule(),
            $this->rule(['result' => ['tax_status' => 'conflicting']]),
        ], ['origin_uf' => 'SP'], '2026-10-25');
    }

    public function test_missing_legal_source_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new TaxRuleResolution())->resolve(
            [$this->rule(['legal_source' => ''])],
            ['origin_uf' => 'SP'], '2026-10-25'
        );
    }
}
