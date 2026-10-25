<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NfeEditorTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador NF-e',
            'email'=>'nfe-admin@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    private function company(string $state='BA'): CompanySetting
    {
        $company=CompanySetting::current();
        $company->update([
            'legal_name'=>'Empresa Teste Ltda',
            'document'=>'12345678000100',
            'state_registration'=>'12345678',
            'state'=>$state,
            'city'=>'Salvador',
            'city_ibge_code'=>'2927408',
            'address'=>'Rua Emitente',
            'address_number'=>'10',
            'district'=>'Centro',
            'crt'=>'1',
        ]);

        return $company->refresh();
    }

    private function nature(array $override=[]): OperationNature
    {
        return OperationNature::query()->create(array_merge([
            'name'=>'Venda de mercadoria',
            'operation_type'=>'outbound',
            'purpose'=>'normal',
            'cfop_internal'=>'5102',
            'cfop_interstate'=>'6102',
            'cfop_inbound_internal'=>'1102',
            'cfop_inbound_interstate'=>'2102',
            'cfop_foreign'=>'7102',
            'override_product_cfop'=>true,
            'presence_default'=>'presential',
            'move_stock'=>true,
            'is_active'=>true,
        ],$override));
    }

    private function customer(array $override=[]): Customer
    {
        return Customer::query()->create(array_merge([
            'name'=>'Cliente Teste Ltda',
            'document'=>'12345678000190',
            'is_customer'=>true,
            'state'=>'BA',
            'city'=>'Salvador',
            'city_ibge_code'=>'2927408',
            'country_code'=>'1058',
            'country_name'=>'BRASIL',
            'address'=>'Rua Teste',
            'address_number'=>'100',
            'district'=>'Centro',
            'final_consumer'=>false,
            'ie_indicator'=>'contributor',
            'state_registration'=>'123456789',
        ],$override));
    }

    private function product(array $override=[]): Product
    {
        return Product::query()->create(array_merge([
            'sku'=>'PROD-NFE-001',
            'name'=>'Produto NF-e',
            'unit'=>'UN',
            'sale_price'=>100,
            'cost_price'=>50,
            'origin'=>'0',
            'ean_gtin'=>'SEM GTIN',
            'ncm'=>'12345678',
            'cest'=>'1234567',
            'fiscal_benefit_code'=>'BENEF01',
            'tax_defaults'=>[
                'cfop_outbound_internal'=>'5101',
                'cfop_outbound_interstate'=>'6101',
                'cfop_inbound_internal'=>'1101',
                'cfop_inbound_interstate'=>'2101',
                'icms_csosn'=>'102',
                'icms_csosn_export'=>'300',
                'icms_csosn_inbound'=>'400',
                'icms_rate'=>18,
                'base_reduction_rate'=>10,
                'simple_credit_rate'=>3,
                'mod_bc'=>'3',
                'mod_bc_st'=>'4',
                'icms_st_rate'=>18,
                'mva_rate'=>40,
                'pis_cst'=>'49',
                'pis_rate'=>0.65,
                'pis_cst_inbound'=>'98',
                'cofins_cst'=>'49',
                'cofins_rate'=>3,
                'cofins_cst_inbound'=>'98',
                'ipi_cst'=>'99',
                'ipi_rate'=>10,
                'ipi_cst_inbound'=>'49',
                'ipi_enq'=>'999',
                'interstate_icms_rate'=>12,
                'internal_icms_rate'=>18,
                'fcp_interstate_rate'=>2,
                'tax_quantity_factor'=>1,
                'petroleum_derived'=>true,
                'anp_code'=>'210203001',
                'anp_description'=>'Produto ANP teste',
                'glp_rate'=>10,
                'gnn_rate'=>20,
                'gni_rate'=>30,
                'starting_value'=>4.50,
                'ibs_cst'=>'000',
                'cbs_cst'=>'000',
                'tax_classification_code'=>'000001',
            ],
            'is_active'=>true,
        ],$override));
    }

    public function test_invoice_editor_has_consistent_visual_hierarchy_and_readable_recipient_summary(): void
    {
        $css=(string)file_get_contents(public_path('css/nfe-form-workflow.css'));
        $script=(string)file_get_contents(public_path('js/nfe-editor.js'));

        self::assertStringContainsString('width:min(1200px,calc(100vw - 36px))',$css);
        self::assertStringContainsString('border-left:3px solid #2b8b6a',$css);
        self::assertStringContainsString('border-bottom-color:#28896b',$css);
        self::assertStringContainsString('background:#f1f8f4',$css);
        self::assertStringContainsString('nfe-recipient-meta',$css);
        self::assertStringContainsString('nfe-recipient-address',$css);
        self::assertStringContainsString('nfe-recipient-meta',$script);
        self::assertStringContainsString('nfe-recipient-address',$script);
        self::assertStringContainsString('esc(customer.city_ibge_code',$script);
    }

    public function test_scrollable_editor_shell_uses_grid_and_not_absolute_positioning(): void
    {
        $css=(string)file_get_contents(public_path('css/erp.css'));
        $script=(string)file_get_contents(public_path('js/nfe-editor.js'));
        self::assertStringContainsString('grid-template-rows:auto minmax(0,1fr) auto',$css);
        self::assertStringContainsString('.nfe-modal-layer .nfe-workspace-dialog .nfe-workspace-body',$css);
        self::assertStringNotContainsString('.nfe-modal-layer .nfe-fixed-shell',$css);
        self::assertStringContainsString("flowBody.scrollTo({top,behavior:'smooth'})",$script);
        $layoutCss=(string)file_get_contents(public_path('css/nfe-form-workflow.css'));
        self::assertStringContainsString('width:min(1200px,calc(100vw - 36px))',$layoutCss);
        self::assertStringContainsString('width:100vw;',$layoutCss);
    }

    public function test_compact_invoice_layout_keeps_every_essential_field_and_all_sections(): void
    {
        $this->actingAs($this->admin());
        $this->company();

        $html=$this->get(route('fiscal.nfe.create'))->assertOk()->getContent();
        foreach (['general','items','payment','transport','other'] as $section) {
            self::assertStringContainsString('id="nfe-section-'.$section.'"',$html);
        }
        self::assertSame(5,substr_count($html,'class="editor-tab-panel nfe-form-section cms-card"'));
        self::assertStringContainsString('class="nfe-general-actions"',$html);
        self::assertStringContainsString('class="nfe-item-header-actions"',$html);
        self::assertStringContainsString('class="editor-grid cols-12 nfe-totals-grid"',$html);
        self::assertStringContainsString('class="nfe-general-dates"',$html);
        self::assertSame(6,substr_count($html,'class="field col-2 nfe-flag-field"'));
        foreach (['nfeNature','nfeOperationType','nfeDestination','nfeIssueDate','nfeIssueTime',
            'nfeCustomer','nfeDiscount','nfeSurcharge','nfeTotalInvoice','nfeFreightValue',
            'nfeItemName','nfeItemNotes','nfeItemCfop','nfePaymentType'] as $id) {
            self::assertSame(1,substr_count($html,'id="'.$id.'"'),$id.' must exist once.');
        }
        self::assertStringContainsString('name="volume_quantity"',$html);
        self::assertStringContainsString('name="gross_weight"',$html);
        self::assertStringNotContainsString('class="field col-4"></div>',$html);

        $css=(string)file_get_contents(public_path('css/nfe-form-workflow.css'));
        $js=(string)file_get_contents(public_path('js/nfe-editor.js'));
        self::assertStringContainsString('grid-template-columns:repeat(5,minmax(0,1fr))',$css);
        self::assertStringContainsString('const group=input.closest(', $js);
    }

    public function test_payment_form_progressively_discloses_advanced_fields_without_removing_them(): void
    {
        $this->actingAs($this->admin());
        $this->company();

        $this->get(route('fiscal.nfe.create'))->assertOk()
            ->assertSee('id="nfePaymentType"',false)
            ->assertSee('id="nfePaymentCondition"',false)
            ->assertSee('id="nfePrimaryPaymentTotal"',false)
            ->assertSee('id="nfeCardPanel" hidden',false)
            ->assertSee('id="nfeOtherPaymentField" hidden',false)
            ->assertSee('id="nfeSplitPayments"',false)
            ->assertSee('id="nfePaymentInstallments"',false)
            ->assertSee('id="nfeExtraPaymentIntegrationField" hidden',false)
            ->assertSee('id="nfeExtraPaymentsBody"',false)
            ->assertSee('id="nfeDuplicateBody"',false);

        $css=(string)file_get_contents(public_path('css/nfe-form-workflow.css'));
        $script=(string)file_get_contents(public_path('js/nfe-editor.js'));
        self::assertStringContainsString('.nfe-modal-layer [hidden]{display:none!important}',$css);
        self::assertStringContainsString("byId('nfePaymentInstallments').open=true",$script);
        self::assertStringContainsString("byId('nfeSplitPayments').open=true",$script);
    }

    public function test_new_nfe_uses_nextor_pattern_and_real_legacy_mapping(): void
    {
        $this->company();
        $this->nature();
        $this->customer();
        $this->product();

        $this->actingAs($this->admin())
            ->get(route('fiscal.nfe.create'))
            ->assertOk()
            ->assertSee('Criar nova NF-e')
            ->assertSee('erp-dialog',false)
            ->assertSee('nextor-modal-layer',false)
            ->assertSee('nextor-modal-window',false)
            ->assertDontSee('nfe-fixed-shell',false)
            ->assertSee('id="nfeScrollRegion"',false)
            ->assertSee('tabindex="0" aria-label="Conteúdo da NF-e"',false)
            ->assertSee('data-nfe-modal',false)
            ->assertSee('id="erpCatalogDialog"',false)
            ->assertSee('dialog-header',false)
            ->assertSee('editor-tabs nfe-flow-nav',false)
            ->assertSee('nfe-form-section cms-card',false)
            ->assertSee('card-header nfe-flow-heading',false)
            ->assertSee('nfe-item-section',false)
            ->assertSee('nfe-tax-review-grid',false)
            ->assertDontSee('data-nfe-item-tab',false)
            ->assertSee('editor-panel',false)
            ->assertSee('cms-table',false)
            ->assertSee('dialog-footer',false)
            ->assertSee('Dados gerais')
            ->assertSee('nfe-emitter-summary',false)
            ->assertSee('nfe-emitter-logo',false)
            ->assertSee('nfe-document-summary',false)
            ->assertSee('Editar dados do emitente')
            ->assertSee('Número da NF-e')
            ->assertSee('Série')
            ->assertSee('Ambiente')
            ->assertSee('Destino da operação')
            ->assertSee('Operação interna')
            ->assertSee('Operação interestadual')
            ->assertSee('Operação com exterior')
            ->assertSee('Finalidade da emissão')
            ->assertSee('Nota de crédito')
            ->assertSee('Nota de débito')
            ->assertSee('Ins. Est. Subst. Trib.')
            ->assertSee('Possui documento referenciado?')
            ->assertSee('Informar data de emissão')
            ->assertSee('Informar data de saída')
            ->assertSee('Informar previsão de entrega')
            ->assertSee('Compra governamental')
            ->assertSee('Pagamento antecipado')
            ->assertSee('Data de emissão')
            ->assertSee('id="nfeIssueTime"',false)
            ->assertSee('Data de saída/entrada')
            ->assertSee('id="nfeExitTime"',false)
            ->assertSee('Limpar campo')
            ->assertSee('Ocultar detalhes')
            ->assertSee('<h2>Dados gerais</h2>',false)
            ->assertSee('Dados do destinatário')
            ->assertSee('Produtos e valores')
            ->assertSee('Transporte')
            ->assertSee('Pagamento')
            ->assertSee('Informações adicionais')
            ->assertSee('Buscar produto cadastrado')
            ->assertSee('Cadastrar produto')
            ->assertSee('Descrição do item')
            ->assertSee('Adicionar')
            ->assertDontSee('Adicionar item detalhado')
            ->assertDontSee('Item avulso')
            ->assertSee('Natureza da operação')
            ->assertSee('Tipo de operação')
            ->assertSee('Presença do comprador')
            ->assertSee('Desconto da nota')
            ->assertSee('Acréscimo da nota')
            ->assertSee('Modalidade do frete')
            ->assertSee('Tipo de pagamento')
            ->assertSee('Adicionar duplicata')
            ->assertSee('Documentos referenciados')
            ->assertSee('Código benefício (cBenef)')
            ->assertSee('CSOSN exportação')
            ->assertSee('CSOSN entrada')
            ->assertSee('Redução da BC')
            ->assertSee('Modalidade BC ST')
            ->assertSee('CST PIS entrada')
            ->assertSee('CST COFINS entrada')
            ->assertSee('CST IPI entrada')
            ->assertSee('ICMS interestadual')
            ->assertSee('FCP interestadual')
            ->assertSee('Código ANP')
            ->assertSee('Lote')
            ->assertSee('RENAVAM')
            ->assertSee('IBS / CBS')
            ->assertDontSee('Retenções de impostos')
            ->assertDontSee('Informações de agropecuária')
            ->assertDontSee('Campos de uso livre')
            ->assertDontSee('Valor original');
    }

    public function test_company_logo_is_served_without_public_storage_symlink(): void
    {
        Storage::fake('public');

        $company=$this->company();
        Storage::disk('public')->put('company/logo.png','fake-logo-bytes');
        $company->update(['logo_path'=>'company/logo.png']);

        $this->actingAs($this->admin())
            ->get(route('company.logo'))
            ->assertOk()
            ->assertContent('fake-logo-bytes');

        $this->nature();
        $this->customer();
        $this->product();

        $this->get(route('fiscal.nfe.create'))
            ->assertOk()
            ->assertSee(route('company.logo'),false);
    }

    public function test_operation_nature_persists_entry_and_exit_cfops(): void
    {
        $this->actingAs($this->admin())
            ->post(route('fiscal.nfe.natures.store'),[
                'name'=>'Compra e venda de mercadoria',
                'operation_type'=>'outbound',
                'purpose'=>'normal',
                'cfop_internal'=>'5102',
                'cfop_interstate'=>'6102',
                'cfop_inbound_internal'=>'1102',
                'cfop_inbound_interstate'=>'2102',
                'cfop_foreign'=>'7102',
                'override_product_cfop'=>'1',
                'move_stock'=>'1',
                'is_active'=>'1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('operation_natures',[
            'name'=>'Compra e venda de mercadoria',
            'cfop_internal'=>'5102',
            'cfop_interstate'=>'6102',
            'cfop_inbound_internal'=>'1102',
            'cfop_inbound_interstate'=>'2102',
            'cfop_foreign'=>'7102',
            'override_product_cfop'=>1,
            'move_stock'=>1,
            'is_active'=>1,
        ]);
    }

    public function test_product_can_store_mapped_nfe_tax_defaults(): void
    {
        $response=$this->actingAs($this->admin())
            ->post(route('products.store'),[
                'sku'=>'MAP-NFE-001',
                'name'=>'Produto Fiscal Mapeado',
                'usage_type'=>'resale',
                'unit'=>'UN',
                'cost_price'=>'10.00',
                'sale_price'=>'20.00',
                'stock_quantity'=>'0.000',
                'minimum_stock'=>'0.000',
                'control_stock'=>'1',
                'is_active'=>'1',
                'origin'=>'0',
                'different_tax_unit'=>'0',
                'ignore_taxes_mode'=>'none',
                'tax_defaults'=>[
                    'cfop_outbound_internal'=>'5102',
                    'cfop_outbound_interstate'=>'6102',
                    'cfop_inbound_internal'=>'1102',
                    'cfop_inbound_interstate'=>'2102',
                    'icms_csosn'=>'102',
                    'icms_csosn_export'=>'300',
                    'icms_csosn_inbound'=>'400',
                    'pis_cst'=>'49',
                    'pis_cst_inbound'=>'98',
                    'cofins_cst'=>'49',
                    'cofins_cst_inbound'=>'98',
                    'ipi_cst'=>'99',
                    'ipi_cst_inbound'=>'49',
                    'interstate_icms_rate'=>'12',
                    'internal_icms_rate'=>'18',
                    'fcp_interstate_rate'=>'2',
                    'anp_code'=>'210203001',
                    'petroleum_derived'=>'1',
                    'ibs_cst'=>'000',
                    'cbs_cst'=>'000',
                    'tax_classification_code'=>'000001',
                ],
            ]);

        $response->assertRedirect(route('products.index'));

        $product=Product::query()->where('sku','MAP-NFE-001')->firstOrFail();
        $this->assertSame('5102',data_get($product->tax_defaults,'cfop_outbound_internal'));
        $this->assertSame('1102',data_get($product->tax_defaults,'cfop_inbound_internal'));
        $this->assertSame('400',data_get($product->tax_defaults,'icms_csosn_inbound'));
        $this->assertSame('98',data_get($product->tax_defaults,'pis_cst_inbound'));
        $this->assertSame('210203001',data_get($product->tax_defaults,'anp_code'));
        $this->assertTrue((bool)data_get($product->tax_defaults,'petroleum_derived'));
    }

    public function test_nfe_draft_uses_global_freight_discount_surcharge_and_mapped_item_tax(): void
    {
        $this->company('BA');
        $nature=$this->nature();
        $customer=$this->customer();
        $product=$this->product();

        $items=[[
            'product_id'=>$product->id,
            'product_name'=>$product->name,
            'product_sku'=>$product->sku,
            'quantity'=>2,
            'dimension_quantity'=>1.5,
            'unit_price'=>100,
            'cfop'=>'',
            'origin'=>'0',
            'ean_gtin'=>'SEM GTIN',
            'unit'=>'UN',
            'tax_unit'=>'UN',
            'ncm'=>'12345678',
            'cest'=>'1234567',
            'fiscal_benefit_code'=>'BENEF01',
            'purchase_order'=>'PED-1',
            'purchase_order_item'=>'1',
            'notes'=>'Teste',
            'tax_data'=>$product->tax_defaults,
            'special_data'=>[
                'petroleum_derived'=>true,
                'anp_code'=>'210203001',
                'batch'=>'LOTE-01',
            ],
        ]];

        $response=$this->actingAs($this->admin())
            ->post(route('fiscal.nfe.store'),[
                'operation_nature_id'=>$nature->id,
                'customer_id'=>$customer->id,
                'operation_type'=>'outbound',
                'presence'=>'presential',
                'issue_date'=>'2026-10-03',
                'issue_time'=>'10:30',
                'expected_delivery_date'=>'2026-10-05',
                'discount'=>'15',
                'surcharge'=>'5',
                'freight_mode'=>'0',
                'freight_value'=>'10',
                'payment_type'=>'17',
                'payment_condition'=>'a_vista',
                'items_json'=>json_encode($items),
                'duplicates_json'=>'[]',
                'references_json'=>json_encode([['key'=>str_repeat('1',44)]]),
                'additional_info'=>'Informação complementar',
            ]);

        $draft=NfeDraft::query()->firstOrFail();

        $response->assertRedirect(route('fiscal.nfe.edit',$draft));
        $this->assertSame('internal',$draft->destination);
        $this->assertFalse($draft->final_consumer);
        $this->assertSame('normal',$draft->purpose);
        $this->assertSame('17',$draft->payment_type);
        $this->assertSame('0',data_get($draft->transport_data,'freight_mode'));
        $this->assertEquals(10.0,(float)data_get($draft->transport_data,'freight_value'));
        $this->assertEquals(300.0,(float)data_get($draft->totals,'products'));
        $this->assertEquals(30.0,(float)data_get($draft->totals,'ipi'));
        $this->assertEquals(330.0,(float)data_get($draft->totals,'total'));
        $this->assertEquals(330.0,(float)data_get($draft->invoice_data,'net_value'));
        $this->assertEquals(330.0,(float)data_get($draft->payments,'0.amount'));
        $this->assertSame('Cliente Teste Ltda',data_get($draft->recipient_snapshot,'name'));
        $this->assertSame('2927408',data_get($draft->recipient_snapshot,'city_ibge_code'));

        $this->assertDatabaseHas('nfe_draft_items',[
            'nfe_draft_id'=>$draft->id,
            'product_id'=>$product->id,
            'cfop'=>'5102',
            'ncm'=>'12345678',
            'line_total'=>300,
        ]);

        $item=$draft->items()->firstOrFail();
        $this->assertEquals(1.5,(float)$item->dimension_quantity);
        $this->assertSame('300',data_get($item->tax_data,'icms_csosn_export'));
        $this->assertSame('400',data_get($item->tax_data,'icms_csosn_inbound'));
        $this->assertSame('210203001',data_get($item->special_data,'anp_code'));
    }

    public function test_nfe_accepts_unregistered_item_without_creating_product(): void
    {
        $this->company('BA');
        $nature=$this->nature();
        $customer=$this->customer();
        $productsBefore=Product::query()->count();

        $items=[[
            'product_id'=>null,
            'product_name'=>'Peça avulsa informada na NF-e',
            'product_sku'=>'AVULSO-01',
            'quantity'=>2,
            'dimension_quantity'=>1,
            'unit_price'=>75,
            'cfop'=>'',
            'origin'=>'0',
            'ean_gtin'=>'SEM GTIN',
            'unit'=>'UN',
            'tax_unit'=>'UN',
            'ncm'=>'84839000',
            'cest'=>'',
            'fiscal_benefit_code'=>'',
            'purchase_order'=>'',
            'purchase_order_item'=>'',
            'notes'=>'Item não cadastrado no catálogo',
            'tax_data'=>[
                'icms_csosn'=>'102',
                'pis_cst'=>'49',
                'cofins_cst'=>'49',
                'ipi_cst'=>'99',
            ],
            'special_data'=>[],
        ]];

        $response=$this->actingAs($this->admin())
            ->post(route('fiscal.nfe.store'),[
                'operation_nature_id'=>$nature->id,
                'customer_id'=>$customer->id,
                'operation_type'=>'outbound',
                'presence'=>'presential',
                'issue_date'=>'2026-10-03',
                'discount'=>'0',
                'surcharge'=>'0',
                'freight_mode'=>'9',
                'freight_value'=>'0',
                'payment_type'=>'01',
                'payment_condition'=>'a_vista',
                'items_json'=>json_encode($items),
                'duplicates_json'=>'[]',
                'references_json'=>'[]',
            ]);

        $draft=NfeDraft::query()->firstOrFail();
        $response->assertRedirect(route('fiscal.nfe.edit',$draft));

        $this->assertSame($productsBefore,Product::query()->count());

        $item=$draft->items()->firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame('Peça avulsa informada na NF-e',$item->product_name);
        $this->assertSame('AVULSO-01',$item->product_sku);
        $this->assertSame('5102',$item->cfop);
        $this->assertSame('84839000',$item->ncm);
        $this->assertEquals(150.0,(float)$item->line_total);
        $this->assertEquals(150.0,(float)data_get($draft->totals,'products'));
    }

    public function test_general_fields_can_override_operation_defaults(): void
    {
        $this->company('BA');
        $nature=$this->nature([
            'operation_type'=>'outbound',
            'purpose'=>'normal',
            'presence_default'=>'not_applicable',
        ]);
        $customer=$this->customer(['state'=>'BA']);
        $product=$this->product();

        $items=[[
            'product_id'=>$product->id,
            'product_name'=>$product->name,
            'product_sku'=>$product->sku,
            'quantity'=>1,
            'dimension_quantity'=>1,
            'unit_price'=>100,
            'origin'=>'0',
            'unit'=>'UN',
            'ncm'=>'12345678',
            'tax_data'=>$product->tax_defaults,
        ]];

        $key=str_repeat('2',44);

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfe.store'),[
                'operation_nature_id'=>$nature->id,
                'customer_id'=>$customer->id,
                'operation_type'=>'inbound',
                'destination'=>'interstate',
                'presence'=>'delivery_home',
                'purpose'=>'credit_note',
                'substitute_state_registration'=>'IE-ST-998877',
                'has_referenced_document'=>'1',
                'inform_issue_datetime'=>'1',
                'issue_date'=>'2026-10-01',
                'issue_time'=>'09:15',
                'inform_exit_datetime'=>'1',
                'exit_date'=>'2026-10-02',
                'exit_time'=>'11:45',
                'inform_expected_delivery_date'=>'1',
                'expected_delivery_date'=>'2026-10-05',
                'government_purchase'=>'1',
                'advance_payment'=>'1',
                'discount'=>'0',
                'surcharge'=>'0',
                'freight_mode'=>'9',
                'freight_value'=>'0',
                'payment_type'=>'01',
                'payment_condition'=>'a_vista',
                'items_json'=>json_encode($items),
                'duplicates_json'=>'[]',
                'references_json'=>json_encode([['key'=>$key]]),
            ])
            ->assertRedirect();

        $draft=NfeDraft::query()->firstOrFail();

        $this->assertSame('inbound',$draft->operation_type);
        $this->assertSame('interstate',$draft->destination);
        $this->assertSame('delivery_home',$draft->presence);
        $this->assertSame('credit_note',$draft->purpose);
        $this->assertSame('IE-ST-998877',$draft->substitute_state_registration);
        $this->assertTrue($draft->has_referenced_document);
        $this->assertTrue($draft->inform_issue_datetime);
        $this->assertTrue($draft->inform_exit_datetime);
        $this->assertTrue($draft->inform_expected_delivery_date);
        $this->assertTrue($draft->government_purchase);
        $this->assertTrue($draft->advance_payment);
        $this->assertSame('2026-10-01',$draft->issue_date->format('Y-m-d'));
        $this->assertSame('2026-10-02',$draft->exit_date->format('Y-m-d'));
        $this->assertSame('2026-10-05',$draft->expected_delivery_date->format('Y-m-d'));
        $this->assertSame($key,data_get($draft->references,'0.key'));
        $this->assertSame('2102',$draft->items()->firstOrFail()->cfop);
    }

    public function test_destination_and_cfop_are_derived_for_interstate_operation(): void
    {
        $this->company('BA');
        $nature=$this->nature();
        $customer=$this->customer(['state'=>'SP','city'=>'São Paulo','city_ibge_code'=>'3550308']);
        $product=$this->product();

        $items=[[
            'product_id'=>$product->id,
            'product_name'=>$product->name,
            'product_sku'=>$product->sku,
            'quantity'=>1,
            'unit_price'=>100,
            'origin'=>'0',
            'unit'=>'UN',
            'ncm'=>'12345678',
            'tax_data'=>$product->tax_defaults,
        ]];

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfe.store'),[
                'operation_nature_id'=>$nature->id,
                'customer_id'=>$customer->id,
                'operation_type'=>'outbound',
                'presence'=>'presential',
                'issue_date'=>'2026-10-03',
                'freight_mode'=>'9',
                'payment_type'=>'01',
                'payment_condition'=>'a_vista',
                'items_json'=>json_encode($items),
                'duplicates_json'=>'[]',
                'references_json'=>'[]',
            ])
            ->assertRedirect();

        $draft=NfeDraft::query()->firstOrFail();
        $this->assertSame('interstate',$draft->destination);
        $this->assertSame('6102',$draft->items()->firstOrFail()->cfop);
    }

    public function test_validate_action_saves_current_form_before_checking(): void
    {
        $this->company();
        $nature=$this->nature();
        $customer=$this->customer();
        $product=$this->product();

        $items=[[
            'product_id'=>$product->id,
            'product_name'=>$product->name,
            'product_sku'=>$product->sku,
            'quantity'=>1,
            'unit_price'=>100,
            'cfop'=>'5102',
            'origin'=>'0',
            'unit'=>'UN',
            'ncm'=>'12345678',
            'tax_data'=>[
                'icms_csosn'=>'102',
                'pis_cst'=>'49',
                'cofins_cst'=>'49',
                'ipi_cst'=>'99',
            ],
        ]];

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfe.store'),[
                'operation_nature_id'=>$nature->id,
                'customer_id'=>$customer->id,
                'operation_type'=>'outbound',
                'presence'=>'presential',
                'issue_date'=>'2026-10-03',
                'issue_time'=>'10:30',
                'discount'=>'0',
                'surcharge'=>'0',
                'freight_mode'=>'9',
                'freight_value'=>'0',
                'payment_type'=>'01',
                'payment_condition'=>'a_vista',
                'items_json'=>json_encode($items),
                'duplicates_json'=>'[]',
                'references_json'=>'[]',
                'after_save'=>'validate',
            ])
            ->assertRedirect();

        $draft=NfeDraft::query()->firstOrFail();
        // O formulário foi salvo, mas uma tributação não suportada pelo
        // emissor não pode ser exibida como pronta para emissão.
        $this->assertNull($draft->validated_at);
        $this->assertEquals(1,$draft->items()->count());
    }

    public function test_product_fiscal_tab_exposes_legacy_mapped_defaults(): void
    {
        $product=$this->product();

        $this->actingAs($this->admin())
            ->get(route('products.edit',$product))
            ->assertOk()
            ->assertSee('CFOP padrão da NF-e')
            ->assertSee('CSOSN exportação')
            ->assertSee('CSOSN entrada')
            ->assertSee('CST PIS entrada')
            ->assertSee('CST COFINS entrada')
            ->assertSee('CST IPI entrada')
            ->assertSee('DIFAL / FCP')
            ->assertSee('Combustível / ANP')
            ->assertSee('IBS / CBS');
    }

    public function test_fiscal_nfe_toolbar_opens_editor_drafts_and_natures(): void
    {
        $this->actingAs($this->admin())
            ->get(route('fiscal.index',['tab'=>'nfe','month'=>'2026-10']))
            ->assertOk()
            ->assertSee(route('fiscal.nfe.create'),false)
            ->assertSee(route('fiscal.nfe.drafts.index'),false)
            ->assertSee(route('fiscal.nfe.natures.index'),false);
    }
}
