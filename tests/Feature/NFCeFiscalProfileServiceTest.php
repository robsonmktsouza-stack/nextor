<?php

namespace Tests\Feature;

use App\Models\FiscalFcpRule;
use App\Models\FiscalTaxGroup;
use App\Models\FiscalTaxRule;
use App\Services\Fiscal\NFCeFiscalProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class NFCeFiscalProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private function item(int $productId = 21, ?int $groupId = null): array
    {
        return [
            'item_type' => 'product',
            'product_id' => $productId,
            'fiscal_tax_group_id' => $groupId,
            'ncm' => '6913.90.00',
        ];
    }

    private function createGroup(array $override = []): FiscalTaxGroup
    {
        return FiscalTaxGroup::query()->create(array_replace([
            'name' => 'Mercadorias revisadas',
            'kind' => 'products',
            'is_active' => true,
            'is_default' => true,
            'cfop_pattern' => 'x102',
            'icms_csosn' => '101',
            'nfce_csosn' => '102',
            'pis_cst' => '49',
            'cofins_cst' => '49',
            'tax_config' => [],
        ], $override));
    }

    private function createRule(array $override = []): FiscalTaxRule
    {
        return FiscalTaxRule::query()->create(array_replace([
            'name' => 'Regra do NCM',
            'document_type' => 'nfce',
            'origin_uf' => 'BA',
            'destination_uf' => 'BA',
            'crt' => '1',
            'ncm_prefix' => '6913',
            'cfop' => '5102',
            'csosn' => '102',
            'pis_cst' => '49',
            'cofins_cst' => '49',
            'priority' => 0,
            'is_active' => true,
        ], $override));
    }

    public function test_specific_rule_precedes_default_group(): void
    {
        $this->createGroup();
        $this->createRule();

        $result = app(NFCeFiscalProfileService::class)->classify($this->item(), '2026-10-08');

        self::assertSame('Regra específica', $result['origin']);
        self::assertSame('Regra do NCM', $result['name']);
        self::assertSame('5102', $result['tax']['cfop']);
        self::assertArrayHasKey('fiscal_rule_id', $result['tax']);
    }

    public function test_explicit_group_precedes_specific_rule(): void
    {
        $group = $this->createGroup(['is_default' => false, 'cfop_pattern' => '5101']);
        $this->createRule();

        $result = app(NFCeFiscalProfileService::class)->classify(
            $this->item(21, $group->id),
            '2026-10-08'
        );

        self::assertSame('Grupo do produto', $result['origin']);
        self::assertSame('5101', $result['tax']['cfop']);
        self::assertSame($group->id, $result['tax']['fiscal_group_id']);
    }

    public function test_falls_back_to_active_default_group(): void
    {
        $group = $this->createGroup();
        $result = app(NFCeFiscalProfileService::class)->classify($this->item(), '2026-10-08');

        self::assertSame('Grupo padrão', $result['origin']);
        self::assertSame($group->id, $result['tax']['fiscal_group_id']);
    }

    public function test_rule_not_in_effect_is_ignored(): void
    {
        $this->createRule(['valid_from' => '2027-01-01']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configure o grupo tributário');
        app(NFCeFiscalProfileService::class)->classify($this->item(), '2026-10-08');
    }

    public function test_ambiguous_rules_fail_instead_of_selecting_random_match(): void
    {
        $this->createRule(['name' => 'A']);
        $this->createRule(['name' => 'B']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Regras fiscais conflitantes');
        app(NFCeFiscalProfileService::class)->classify($this->item(), '2026-10-08');
    }

    public function test_active_fcp_on_matching_ncm_blocks_unsupported_calculation(): void
    {
        $this->createGroup();
        FiscalFcpRule::query()->create([
            'uf' => 'BA',
            'ncm_prefix' => '6913',
            'rate' => '2.0000',
            'is_active' => true,
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FCP');
        app(NFCeFiscalProfileService::class)->classify($this->item(), '2026-10-08');
    }

    public function test_fcp_outside_ncm_and_inactive_rule_do_not_block(): void
    {
        $this->createGroup();
        FiscalFcpRule::query()->create([
            'uf' => 'BA',
            'ncm_prefix' => '2203',
            'rate' => '2.0000',
            'is_active' => true,
        ]);
        FiscalFcpRule::query()->create([
            'uf' => 'BA',
            'ncm_prefix' => '6913',
            'rate' => '2.0000',
            'is_active' => false,
        ]);

        self::assertSame(
            'Grupo padrão',
            app(NFCeFiscalProfileService::class)->classify($this->item(), '2026-10-08')['origin']
        );
    }

    public function test_explicit_legacy_product_tax_values_are_preserved_without_new_group(): void
    {
        $item = $this->item();
        $item['tax_defaults'] = [
            'cfop_outbound_internal' => '5102',
            'icms_csosn' => '102',
            'pis_cst' => '49',
            'cofins_cst' => '49',
        ];

        $result = app(NFCeFiscalProfileService::class)->classify($item, '2026-10-08');

        self::assertSame('Cadastro fiscal', $result['origin']);
        self::assertSame('product', $result['tax']['fiscal_config_source']);
        self::assertSame('5102', $result['tax']['cfop_outbound_internal']);
    }

    public function test_partial_legacy_tax_values_do_not_generate_guessed_codes(): void
    {
        $item = $this->item();
        $item['tax_defaults'] = ['icms_csosn' => '102'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incompletos');

        app(NFCeFiscalProfileService::class)->classify($item, '2026-10-08');
    }

    public function test_missing_ncm_blocks_classification(): void
    {
        $item = $this->item();
        $item['ncm'] = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NCM');
        app(NFCeFiscalProfileService::class)->classify($item, '2026-10-08');
    }

    public function test_cannot_use_unsupported_group_in_preview(): void
    {
        $group = $this->createGroup(['tax_config' => ['icms_st_rate' => '18']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não está implementado');
        app(NFCeFiscalProfileService::class)->classify($this->item(21, $group->id), '2026-10-08');
    }
}
