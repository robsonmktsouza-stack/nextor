<?php

namespace Tests\Feature;

use App\Models\FiscalTaxRule;
use App\Services\Fiscal\FiscalTaxRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class FiscalTaxRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private function createRule(array $override = []): FiscalTaxRule
    {
        return FiscalTaxRule::query()->create(array_replace([
            'name' => 'Regra conferida',
            'document_type' => 'nfce',
            'origin_uf' => 'BA',
            'destination_uf' => 'BA',
            'crt' => '1',
            'cfop' => '5102',
            'csosn' => '102',
            'pis_cst' => '49',
            'cofins_cst' => '49',
            'priority' => 0,
            'is_active' => true,
        ], $override));
    }

    private function match(array $item, string $date = '2026-10-08', string $to = 'BA'): ?FiscalTaxRule
    {
        return app(FiscalTaxRuleResolver::class)
            ->resolve('nfce', 'BA', $to, '1', $item, $date);
    }

    public function test_rule_form_accepts_sp_internal_rule_through_system_settings(): void
    {
        $user=\App\Models\User::query()->create([
            'name'=>'Administrador','email'=>'uf-test@example.com',
            'password'=>'senhaSegura123','role'=>'admin','is_active'=>true,
        ]);
        $this->actingAs($user)->get(route('fiscal.tax-rules.index'))
            ->assertOk()->assertSee('UF origem')->assertSee('UF destino');

        $this->actingAs($user)->post(route('fiscal.tax-rules.store'),[
            'name'=>'Regra interna SP','document_type'=>'nfce',
            'origin_uf'=>'SP','destination_uf'=>'SP','crt'=>'1',
            'cfop'=>'5102','csosn'=>'102','pis_cst'=>'49',
            'cofins_cst'=>'49','priority'=>0,'is_active'=>'1',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fiscal_tax_rules',[
            'name'=>'Regra interna SP','origin_uf'=>'SP',
            'destination_uf'=>'SP','crt'=>'1',
        ]);
    }

    public function test_rule_form_rejects_interstate_nfce_rule(): void
    {
        $user=\App\Models\User::query()->create([
            'name'=>'Administrador','email'=>'uf-test-2@example.com',
            'password'=>'senhaSegura123','role'=>'admin','is_active'=>true,
        ]);
        $this->actingAs($user)->post(route('fiscal.tax-rules.store'),[
            'name'=>'Regra indevida','document_type'=>'nfce',
            'origin_uf'=>'BA','destination_uf'=>'SP','crt'=>'1',
            'cfop'=>'5102','csosn'=>'102','pis_cst'=>'49',
            'cofins_cst'=>'49','priority'=>0,
        ])->assertSessionHasErrors('destination_uf');
        $this->assertDatabaseMissing('fiscal_tax_rules',['name'=>'Regra indevida']);
    }

    public function test_specific_product_rule_takes_precedence_over_generic_rule(): void
    {
        $general = $this->createRule(['name' => 'Geral', 'priority' => 0]);
        $specific = $this->createRule(['name' => 'Produto', 'product_id' => 123, 'priority' => 0]);

        self::assertSame($specific->id, $this->match(['product_id' => 123, 'ncm' => '69139000'])->id);
        self::assertSame($general->id, $this->match(['product_id' => 456, 'ncm' => '69139000'])->id);
    }

    public function test_ncm_prefix_and_period_must_match(): void
    {
        $general = $this->createRule(['name' => 'Geral']);
        $prefix = $this->createRule([
            'name' => 'NCM específico',
            'ncm_prefix' => '6913',
            'valid_from' => '2027-01-01',
        ]);

        self::assertSame($general->id, $this->match(['product_id' => 40, 'ncm' => '6913.90.00'], '2026-10-08')->id);
        self::assertSame($prefix->id, $this->match(['product_id' => 40, 'ncm' => '6913.90.00'], '2027-01-05')->id);
    }

    public function test_disabled_and_wrong_destination_rule_cannot_be_used(): void
    {
        $this->createRule(['name' => 'Inativa', 'is_active' => false]);
        self::assertNull($this->match(['product_id' => 1, 'ncm' => '69139000']));
        $this->createRule(['name' => 'BA']);
        self::assertNull($this->match(['product_id' => 1, 'ncm' => '69139000'], '2026-10-08', 'SP'));
    }

    public function test_ambiguous_active_rules_fail_closed(): void
    {
        $this->createRule(['name' => 'Regra A', 'priority' => 5]);
        $this->createRule(['name' => 'Regra B', 'priority' => 5]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Regras fiscais conflitantes');
        $this->match(['product_id' => 4, 'ncm' => '69139000']);
    }
}
