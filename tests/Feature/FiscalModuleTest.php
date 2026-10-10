<?php

namespace Tests\Feature;

use App\Models\FiscalDocumentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('MDF-e')
            ->assertDontSee('fiscal-summary-grid',false)
            ->assertDontSee('fiscal-config-strip',false)
            ->assertDontSee('Em processamento')
            ->assertDontSee('Fiscal geral')
            ->assertDontSee('Próximo número')
            ->assertSee('sales-module-tabs',false)
            ->assertSee('grid-actionbar',false)
            ->assertSee('grid-actions-left',false)
            ->assertSee('grid-actions-right',false)
            ->assertSee('period-current',false)
            ->assertSee('grid-filter-button',false)
            ->assertSee('cms-table',false)
            ->assertSee('Mês atual')
            ->assertDontSee('fiscal-actionbar',false)
            ->assertDontSee('fiscal-new-action',false)
            ->assertDontSee('TOTAL LISTADO')
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

        $admin=$this->admin();

        $this->actingAs($admin)
            ->get(route('fiscal.index',['tab'=>'nfe','month'=>'2026-10']))
            ->assertOk()
            ->assertSee('Outubro 2026')
            ->assertSee('101')
            ->assertDontSee('88')
            ->assertSee('Autorizada')
            ->assertSee('status-ok',false);

        $this->actingAs($admin)
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

    public function test_nfce_utilities_contain_cancel_and_inutilization(): void
    {
        $this->actingAs($this->admin())
            ->get(route('fiscal.index',['tab'=>'nfce']))
            ->assertOk()
            ->assertSee('data-fiscal-cancel',false)
            ->assertSee('Cancelamento')
            ->assertSee('Inutilização')
            ->assertSee('nfceCancelDialog')
            ->assertSee('data-no-loading hidden',false);
    }

    public function test_authorized_nfce_opens_filled_emission_form_in_readonly_mode(): void
    {
        $document=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce',
            'status'=>'authorized',
            'environment'=>'homologation',
            'series'=>1,
            'document_number'=>3,
            'access_key'=>str_repeat('1',44),
            'protocol'=>str_repeat('2',15),
            'xml_path'=>'fiscal/nfce/no-test-artifact/authorized.xml',
            'authorized_at'=>now()->subMinute(),
            'prepared_at'=>now()->subMinutes(2),
            'source_snapshot'=>[
                'consumer_document'=>'12345678901','consumer_name'=>'Consumidor da época',
                'items'=>[[
                    'product_id'=>991,'sku'=>'SKU-HISTORICO',
                    'name'=>'Produto histórico congelado',
                    'quantity'=>'2','unit_price'=>'12.90','discount'=>'0',
                ]],
                'payments'=>[[
                    'payment_method'=>'cash','payment_kind'=>'cash','amount'=>'25.80',
                ]],
            ],
        ]);

        $this->actingAs($this->admin())
            ->get(route('fiscal.show',$document))
            ->assertOk()
            ->assertSee('nfceEditorForm',false)
            ->assertSee('data-readonly="1"',false)
            ->assertSee('NFC-e 1/3')
            ->assertSee('Dados gerais')
            ->assertSee('Consumidor')
            ->assertSee('Produtos')
            ->assertSee('Pagamento')
            ->assertSee('Resumo')
            ->assertSee('Produto histórico congelado')
            ->assertSee('SKU-HISTORICO')
            ->assertSee('R$ 25,80')
            ->assertSee('Dinheiro')
            ->assertDontSee('campos bloqueados para alteração')
            ->assertDontSee('nfceProductsJson',false)
            ->assertDontSee('name="submit_mode"',false)
            ->assertSee('Consumidor da época')
            ->assertSee('data-no-loading download',false)
            ->assertSee('data-dialog-open="nfceCancelDialog"',false)
            ->assertDontSee('Salvar para emissão')
            ->assertDontSee('Emitir NFC-e');
    }

    public function test_archived_xml_is_authoritative_for_readonly_products_payments_and_amounts(): void
    {
        Storage::fake('local');
        $document=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','status'=>'authorized',
            'environment'=>'homologation','series'=>1,'document_number'=>5,
            'access_key'=>str_repeat('5',44),'protocol'=>str_repeat('8',15),
            'authorized_at'=>now()->subHour(),'prepared_at'=>now()->subHours(2),
            'source_snapshot'=>[
                'items'=>[['name'=>'Produto do cadastro antigo','sku'=>'OLD',
                    'quantity'=>'1','unit_price'=>'99.99','discount'=>'0']],
                'payments'=>[['payment_method'=>'cash','payment_kind'=>'cash','amount'=>'99.99']],
                'consumer_name'=>'Nome alterado depois','total'=>'99.99',
            ],
        ]);
        $path='fiscal/nfce/'.$document->id.'/authorized.xml';
        Storage::disk('local')->put($path, <<<'XML'
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe><infNFe><ide><natOp>VENDA</natOp><dhEmi>2026-10-09T20:25:38-03:00</dhEmi><indPres>1</indPres></ide>
  <emit><CNPJ>39323356000100</CNPJ><xNome>EMPRESA ORIGINAL</xNome></emit>
  <dest><CPF>12345678901</CPF><xNome>CONSUMIDOR ORIGINAL</xNome></dest>
  <det><prod><cProd>PROD-X</cProd><xProd>Produto fiscal original</xProd><qCom>2.0000</qCom><vUnCom>6.4500</vUnCom><vProd>12.90</vProd></prod></det>
  <total><ICMSTot><vProd>12.90</vProd><vDesc>0.00</vDesc><vNF>12.90</vNF></ICMSTot></total>
  <pag><detPag><tPag>03</tPag><vPag>12.90</vPag></detPag></pag>
  <infAdic><infCpl>Observação original</infCpl></infAdic>
  </infNFe></NFe>
</nfeProc>
XML);
        $document->update(['xml_path'=>$path]);

        $this->actingAs($this->admin())
            ->get(route('fiscal.show',$document))
            ->assertOk()
            ->assertSee('EMPRESA ORIGINAL')
            ->assertSee('CONSUMIDOR ORIGINAL')
            ->assertSee('Produto fiscal original')
            ->assertSee('PROD-X')
            ->assertSee('Cartão de crédito')
            ->assertSee('R$ 12,90')
            ->assertSee('Observação original')
            ->assertDontSee('Produto do cadastro antigo')
            ->assertDontSee('Nome alterado depois')
            ->assertDontSee('R$ 99,99');
    }

    public function test_cancelled_nfce_uses_same_readonly_form_without_cancellation_action(): void
    {
        $document=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','status'=>'cancelled',
            'environment'=>'homologation','series'=>1,'document_number'=>8,
            'access_key'=>str_repeat('7',44),
            'protocol'=>str_repeat('8',15),
            'authorized_at'=>now()->subHour(),'cancelled_at'=>now(),
            'source_snapshot'=>[
                'items'=>[['product_id'=>45,'name'=>'Produto preservado','sku'=>'SKU45',
                    'quantity'=>'1','unit_price'=>'10.00','discount'=>'0.00']],
                'payments'=>[['payment_method'=>'cash','payment_kind'=>'cash','amount'=>'10.00']],
            ],
        ]);
        $this->actingAs($this->admin())
            ->get(route('fiscal.show',$document))
            ->assertOk()
            ->assertSee('data-readonly="1"',false)
            ->assertSee('Cancelada')
            ->assertSee('Produto preservado')
            ->assertDontSee('data-dialog-open="nfceCancelDialog"',false);
    }

    public function test_cancellation_modals_do_not_trigger_stacked_confirmation(): void
    {
        $this->actingAs($this->admin())
            ->get(route('fiscal.index',['tab'=>'nfce']))
            ->assertOk()
            ->assertSee('id="nfceCancelDialog"',false);

        foreach (['index.blade.php','show.blade.php'] as $view) {
            $source=file_get_contents(resource_path('views/fiscal/'.$view));
            $dialog=explode('</dialog>',explode('id="nfceCancelDialog"',$source,2)[1] ?? '',2)[0];

            $this->assertNotSame('',$dialog);
            $this->assertStringContainsString('name="no_circulation"',$dialog);
            $this->assertStringNotContainsString('data-confirm-submit',$dialog);
        }
    }

    public function test_inutilization_page_identifies_test_environment_and_always_requires_confirmation(): void
    {
        \App\Models\AppSetting::put('nfce','environment','homologation');

        $this->actingAs($this->admin())
            ->get(route('fiscal.nfce.inutilizations'))
            ->assertOk()
            ->assertSee('sales-module-tabs',false)
            ->assertSee('NF-e')
            ->assertSee('NFS-e')
            ->assertSee('CT-e')
            ->assertSee('MDF-e')
            ->assertSee('NFC-e')
            ->assertSee('Homologação — teste')
            ->assertSee('data-confirm-kind="action"',false)
            ->assertSee('Confirmar inutilização');
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
