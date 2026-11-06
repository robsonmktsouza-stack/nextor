<?php

namespace Tests\Feature;

use App\Models\FiscalCatalogEntry;
use App\Models\FiscalCatalogRelease;
use App\Models\FiscalTaxRule;
use App\Models\FiscalTaxRuleRevision;
use App\Models\User;
use App\Support\FiscalPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommercialFiscalPresentationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create(['name'=>'Responsável fiscal','email'=>'fiscal-presentation@example.test',
            'password'=>'senhaSegura123','role'=>'admin','is_active'=>true]);
    }

    public function test_catalog_shows_business_availability_without_exposing_raw_conditions_or_integrity_metadata(): void
    {
        $release=FiscalCatalogRelease::query()->create(['version'=>'internal-build-99','schema_version'=>1,
            'sha256'=>str_repeat('a',64),'consulted_at'=>'2026-10-26','sources'=>[],
            'coverage'=>['benefit'=>['status'=>'partial','scope'=>'UF']], 'entry_count'=>1,'installed_at'=>now()]);
        $entry=FiscalCatalogEntry::query()->create(['release_id'=>$release->id,'kind'=>'benefit','code'=>'AC30002',
            'description'=>'Benefício fiscal para conferência','status'=>'template','uf'=>'AC','entry_key'=>'entry-internal-key',
            'source'=>['reference'=>'Decreto 11.045/2022','url'=>'https://www.sefaz.ac.gov.br/'],
            'conditions'=>['Um código textual prescrito no art. 5º; modelo bloqueado por formato e vigência não verificados.'],
            'parameters'=>['automatic_application'=>false,'icms_csts'=>['20']]]);
        $before=$entry->fresh()->getRawOriginal();
        $response=$this->actingAs($this->admin())->get(route('fiscal.catalog.index',['kind'=>'benefit']))
            ->assertOk()->assertSee('Benefício fiscal não disponível para utilização.')
            ->assertSee('Decreto 11.045/2022')->assertSee('CST ICMS permitidos')->assertSee('20')
            ->assertDontSee('internal-build-99')->assertDontSee('automatic_application')
            ->assertDontSee('modelo bloqueado por formato')->assertDontSee('<pre',false)
            ->assertDontSee(str_repeat('a',64));
        $dom=new \DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try { $dom->loadHTML($response->getContent(),LIBXML_NONET); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        $codeCell=(new \DOMXPath($dom))->query('//td[contains(concat(" ",normalize-space(@class)," ")," nowrap ")]/strong[text()="AC30002"]');
        self::assertSame(1,$codeCell->length,'O código fiscal deve permanecer em uma única linha.');
        self::assertSame($before,$entry->fresh()->getRawOriginal());
    }

    public function test_rule_history_has_labels_and_responsible_name_while_the_original_snapshot_is_preserved(): void
    {
        $user=$this->admin();
        $rule=FiscalTaxRule::query()->create(['name'=>'Venda revisada','document_type'=>'nfe',
            'origin_uf'=>'BA','destination_uf'=>'BA','crt'=>'1','cfop'=>'5102','csosn'=>'102',
            'pis_cst'=>'49','cofins_cst'=>'49','operation_type'=>'outbound','purpose'=>'normal',
            'recipient_profile'=>'non_contributor','is_active'=>false]);
        $revision=FiscalTaxRuleRevision::query()->create(['fiscal_tax_rule_id'=>$rule->id,'revision'=>1,
            'changed_by'=>$user->id,'snapshot'=>['name'=>'Venda anterior','operation_type'=>'outbound',
                'tax_parameters'=>['icms_st_base'=>'125.00'],'catalog_metadata'=>['private_note'=>'snapshot-only']],
            'snapshot_hash'=>str_repeat('b',64),'created_at'=>now()]);
        $before=$revision->fresh()->getRawOriginal();
        $this->actingAs($user)->get(route('fiscal.tax-rules.index'))->assertOk()
            ->assertSee('Responsável fiscal')->assertSee('Venda anterior')->assertSee('Saída')
            ->assertSee('Não contribuinte')->assertSee('ICMS ST base')->assertSee('125.00')
            ->assertDontSee('snapshot-only')->assertDontSee('snapshot_hash')->assertDontSee(str_repeat('b',64))
            ->assertDontSee('<pre',false);
        self::assertSame($before,$revision->fresh()->getRawOriginal());
    }

    public function test_audit_projection_keeps_fiscal_codes_and_skips_unknown_internal_metadata(): void
    {
        self::assertSame([
            ['label'=>'CST ICMS','value'=>'00'],
            ['label'=>'Tributos · IBS estadual alíquota','value'=>'0.1000'],
        ],FiscalPresentation::rows(['id'=>25,'icms_cst'=>'00','tax_parameters'=>['ibs_uf_rate'=>'0.1000'],
            'snapshot_hash'=>'hidden','catalog_version'=>'internal','catalog_metadata'=>['token'=>'hidden']]));
        self::assertSame('À vista',FiscalPresentation::value('a_vista'));
    }

    public function test_every_supported_tax_parameter_has_a_readable_audit_label(): void
    {
        $missing=[];
        foreach(\App\Services\Fiscal\Nfe55TaxParameters::RULE_PARAMETERS as $field){
            if(FiscalPresentation::label($field)===null)$missing[]=$field;
        }
        self::assertSame([],$missing);
    }
}
