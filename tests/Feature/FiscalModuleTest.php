<?php

namespace Tests\Feature;

use App\Models\FiscalDocumentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador Fiscal',
            'email'=>'fiscal-admin@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    public function test_fiscal_module_exposes_all_document_tabs(): void
    {
        $this->actingAs($this->admin())
            ->get(route('fiscal.index'))
            ->assertOk()
            ->assertSee('NF-e')
            ->assertSee('NFC-e')
            ->assertSee('NFS-e')
            ->assertSee('CT-e')
            ->assertDontSee('fiscal-summary-grid',false)
            ->assertDontSee('fiscal-config-strip',false)
            ->assertDontSee('Em processamento')
            ->assertDontSee('Fiscal geral')
            ->assertDontSee('Próximo número')
            ->assertSee('fiscal-actionbar',false)
            ->assertSee('Mês atual')
            ->assertSee('TOTAL LISTADO')
            ->assertDontSee('conector fiscal externo');
    }

    public function test_fiscal_tab_only_lists_selected_document_type(): void
    {
        FiscalDocumentJob::query()->create([
            'document_type'=>'nfe',
            'status'=>'prepared',
            'environment'=>'homologation',
            'series'=>1,
            'document_number'=>15,
            'prepared_at'=>now(),
        ]);

        FiscalDocumentJob::query()->create([
            'document_type'=>'nfce',
            'status'=>'prepared',
            'environment'=>'homologation',
            'series'=>2,
            'document_number'=>99,
            'prepared_at'=>now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('fiscal.index',['tab'=>'nfe']))
            ->assertOk()
            ->assertSee('15')
            ->assertDontSee('99');
    }

    public function test_fiscal_month_navigation_filters_documents(): void
    {
        FiscalDocumentJob::query()->create([
            'document_type'=>'nfe',
            'status'=>'authorized',
            'environment'=>'production',
            'series'=>1,
            'document_number'=>101,
            'prepared_at'=>'2026-10-03 10:00:00',
        ]);

        FiscalDocumentJob::query()->create([
            'document_type'=>'nfe',
            'status'=>'authorized',
            'environment'=>'production',
            'series'=>1,
            'document_number'=>88,
            'prepared_at'=>'2026-09-15 10:00:00',
        ]);

        $this->actingAs($this->admin())
            ->get(route('fiscal.index',['tab'=>'nfe','month'=>'2026-10']))
            ->assertOk()
            ->assertSee('Outubro 2026')
            ->assertSee('101')
            ->assertDontSee('88')
            ->assertSee('fiscal-row-authorized',false);

        $this->actingAs($this->admin())
            ->get(route('fiscal.index',['tab'=>'nfe','month'=>'2026-09']))
            ->assertOk()
            ->assertSee('Setembro 2026')
            ->assertSee('88')
            ->assertDontSee('101');
    }

    public function test_fiscal_document_has_detail_page(): void
    {
        $document=FiscalDocumentJob::query()->create([
            'document_type'=>'nfse',
            'status'=>'authorized',
            'environment'=>'production',
            'series'=>1,
            'document_number'=>321,
            'protocol'=>'PROTO-321',
            'prepared_at'=>now(),
            'authorized_at'=>now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('fiscal.show',$document))
            ->assertOk()
            ->assertSee('NFS-e 1/321')
            ->assertSee('PROTO-321')
            ->assertSee('Autorizado');
    }

    public function test_user_without_fiscal_permission_cannot_open_module(): void
    {
        $user=User::query()->create([
            'name'=>'Vendas',
            'email'=>'sales-only@example.com',
            'password'=>'senhaSegura123',
            'role'=>'sales',
            'is_active'=>true,
            'permissions'=>['sales'],
        ]);

        $this->actingAs($user)->get(route('fiscal.index'))->assertForbidden();
    }
}
