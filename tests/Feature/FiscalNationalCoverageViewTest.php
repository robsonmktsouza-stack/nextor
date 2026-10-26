<?php

namespace Tests\Feature;

use App\Models\{FiscalCatalogRelease, User};
use App\Services\Fiscal\FiscalCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FiscalNationalCoverageViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Matrix tests own their small reference snapshot. Keep unrelated
        // company models; automatic migration provisioning is tested separately.
        \Illuminate\Support\Facades\DB::table('fiscal_catalog_entries')->delete();
        \Illuminate\Support\Facades\DB::table('fiscal_catalog_releases')->delete();
    }

    private function admin(): User
    {
        return User::query()->create(['name'=>'Fiscal teste','email'=>'national-coverage@example.test',
            'password'=>'password','role'=>'admin','is_active'=>true]);
    }

    public function test_matrix_exposes_all_27_states_without_extending_state_benefit_completeness(): void
    {
        $date=now('UTC')->toDateString();
        $release=app(FiscalCatalogService::class)->import(['schema_version'=>1,
            'version'=>'national-view-fixture','consulted_at'=>$date,
            'sources'=>[['id'=>'fixture','url'=>'https://www.nfe.fazenda.gov.br/',
                'reference'=>'Fixture de teste da interface, sem classificação tributária',
                'consulted_at'=>$date]],
            'coverage'=>['cfop'=>['status'=>'complete','scope'=>'BR'],
                'ncm'=>['status'=>'complete','scope'=>'BR'],
                'cest'=>['status'=>'complete','scope'=>'BR'],
                'benefit'=>['status'=>'partial','scope'=>'UF','by_uf'=>[
                    'SP'=>['status'=>'complete'],
                    'BA'=>['status'=>'not_bundled','note'=>'Não foi incorporada tabela estadual verificada.'],
                ]]],
            'entries'=>[['kind'=>'cfop','code'=>'5102','description'=>'CFOP de teste','source_id'=>'fixture'],
                ['kind'=>'ncm','code'=>'61091000','description'=>'NCM de teste','source_id'=>'fixture'],
                ['kind'=>'cest','code'=>'0100100','description'=>'CEST de teste','source_id'=>'fixture'],
                ['kind'=>'benefit','code'=>'SP010001','description'=>'Benefício de teste',
                    'source_id'=>'fixture','uf'=>'SP','valid_from'=>'2026-01-01',
                    'parameters'=>['icms_csts'=>['40']]],
            ]]);
        $this->actingAs($this->admin())->get(route('fiscal.catalog.index',['tab'=>'coverage']))
            ->assertOk()->assertSee('Consulta por estado')->assertSee('Data de referência')
            ->assertSee('Sem tabela validada')->assertSee('Benefícios fiscais não disponíveis para utilização automática nesta UF.')->assertDontSee('Não foi incorporada tabela estadual verificada.')
            ->assertViewHas('coverageByUf',function(array $rows): bool {
                return count($rows)===27 && $rows['BA']['cfop']['complete']
                    && $rows['AM']['cest']['complete'] && $rows['SP']['benefit']['complete']
                    && !$rows['BA']['benefit']['complete'] && !$rows['RJ']['benefit']['complete'];
            });
        self::assertSame(1,FiscalCatalogRelease::query()->count());
        $this->get(route('fiscal.catalog.index',['tab'=>'coverage','release'=>$release->id,'uf'=>'ZZ']))
            ->assertSessionHasErrors('uf');
    }

    public function test_empty_installation_can_consult_matrix_without_creating_catalog_or_rules(): void
    {
        $this->actingAs($this->admin())->get(route('fiscal.catalog.index',['tab'=>'coverage']))
            ->assertOk()->assertViewHas('coverageByUf',fn(array $rows)=>count($rows)===27
                && !$rows['BA']['cfop']['complete'] && !$rows['SP']['benefit']['complete']);
        self::assertSame(0,FiscalCatalogRelease::query()->count());
    }
}
