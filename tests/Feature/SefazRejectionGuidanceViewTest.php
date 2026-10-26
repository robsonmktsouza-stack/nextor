<?php

namespace Tests\Feature;

use App\Models\FiscalDocumentJob;
use App\Models\NFCeInutilization;
use App\Models\Nfe55Inutilization;
use App\Models\NfeDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SefazRejectionGuidanceViewTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Responsável fiscal', 'email'=>'sefaz-guidance@example.com',
            'password'=>'senhaSegura123', 'role'=>'admin', 'is_active'=>true,
        ]);
    }

    public function test_nfe_rejection_explains_the_item_without_enabling_retransmission_or_mutating_the_note(): void
    {
        Storage::fake('local');
        Bus::fake();
        $note=NfeDraft::query()->create([
            'status'=>'rejected', 'issue_date'=>'2026-10-26', 'environment'=>'homologation',
            'series'=>1, 'document_number'=>12, 'access_key'=>str_repeat('1',44),
            'error_message'=>'Retorno da SEFAZ: 778 - Rejeição: Informado NCM inexistente [nItem:2]',
        ]);
        $original=$note->refresh()->getRawOriginal();
        $response=$this->actingAs($this->admin())->get(route('fiscal.nfe.edit',$note));
        $response->assertOk()->assertSee('data-sefaz-guidance',false)
            ->assertSee('778 —')->assertSee('Item 2')->assertSee('Detalhes técnicos')
            ->assertSee('Informado NCM inexistente');
        $this->assertNoRetryButton($response->getContent());
        self::assertSame($original,$note->fresh()->getRawOriginal());
        Bus::assertNothingDispatched();
    }

    public function test_pending_duplicate_has_guidance_and_consultation_without_a_new_send_button(): void
    {
        Storage::fake('local');
        Bus::fake();
        $note=NfeDraft::query()->create([
            'status'=>'pending', 'issue_date'=>'2026-10-26', 'environment'=>'homologation',
            'series'=>1, 'document_number'=>13, 'access_key'=>str_repeat('2',44),
            'error_message'=>'Retorno da SEFAZ: 539 - Rejeição: Duplicidade de NF-e, com diferença na Chave de Acesso',
        ]);
        $response=$this->actingAs($this->admin())->get(route('fiscal.nfe.edit',$note));
        $response
            ->assertOk()->assertSee('data-sefaz-guidance',false)->assertSee('539 —')
            ->assertSee('Consultar SEFAZ');
        $this->assertNoRetryButton($response->getContent());
        self::assertSame('pending',$note->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_readonly_nfce_displays_unknown_rejection_once_and_preserves_escaped_original(): void
    {
        Storage::fake('local');
        $note=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce', 'status'=>'rejected', 'environment'=>'homologation',
            'emission_mode'=>'normal', 'series'=>1, 'document_number'=>14,
            'error_message'=>'Retorno SEFAZ: 9998 - Rejeição: <script>alert("sefaz")</script>',
        ]);
        $response=$this->actingAs($this->admin())->get(route('fiscal.show',$note));
        $response->assertOk()->assertSee('data-sefaz-guidance',false)
            ->assertSee('data-nfce-rejection',false)->assertSee('9998 —')
            ->assertSee('&lt;script&gt;',false)
            ->assertDontSee('<script>alert("sefaz")</script>',false)
            ->assertDontSee('Salvar e transmitir');
        self::assertSame(1,substr_count($response->getContent(),'data-sefaz-guidance'));
        self::assertSame('rejected',$note->fresh()->status);
    }

    public function test_no_error_does_not_add_a_permanent_guidance_banner_to_the_nfe_editor(): void
    {
        Storage::fake('local');
        $note=NfeDraft::query()->create([
            'status'=>'draft', 'issue_date'=>'2026-10-26', 'environment'=>'homologation',
        ]);
        $this->actingAs($this->admin())->get(route('fiscal.nfe.edit',$note))
            ->assertOk()->assertDontSee('data-sefaz-guidance',false);
    }

    public function test_other_dfe_keeps_its_original_error_without_using_the_nfe_code_namespace(): void
    {
        $note=FiscalDocumentJob::query()->create([
            'document_type'=>'cte', 'status'=>'rejected', 'environment'=>'homologation',
            'emission_mode'=>'normal', 'series'=>1, 'document_number'=>9,
            'error_message'=>'Código CT-e 778: retorno preservado deste modelo.',
        ]);
        $this->actingAs($this->admin())->get(route('fiscal.show',$note))
            ->assertOk()->assertSee('Código CT-e 778: retorno preservado deste modelo.')
            ->assertDontSee('data-sefaz-guidance',false);
    }

    public function test_both_inutilization_lists_explain_rejected_requests_without_creating_another_request(): void
    {
        Storage::fake('local');
        Bus::fake();
        $this->actingAs($this->admin());
        foreach([
            [Nfe55Inutilization::class,'fiscal.nfe.inutilizations.index'],
            [NFCeInutilization::class,'fiscal.nfce.inutilizations'],
        ] as [$model,$route]){
            $record=$model::query()->create([
                'environment'=>'homologation', 'issuer_document'=>'12345678000199',
                'year'=>2026, 'series'=>1, 'first_number'=>15, 'last_number'=>16,
                'reason'=>'Lacuna de numeração para teste', 'status'=>'rejected',
                'error_message'=>'Rejeição SEFAZ: 256 - Rejeição: Uma NF-e da faixa já está inutilizada',
            ]);
            $original=$record->refresh()->getRawOriginal();
            $this->get(route($route))->assertOk()->assertSee('Ver orientação')
                ->assertSee('data-sefaz-guidance',false)->assertSee('256 —');
            self::assertSame($original,$record->fresh()->getRawOriginal());
            self::assertSame(1,$model::query()->count());
        }
        Bus::assertNothingDispatched();
    }

    public function test_partial_escapes_external_text_and_links_only_to_official_https_sources(): void
    {
        $guidance=[
            'code'=>'778', 'title'=>'<b>Título</b>', 'summary'=>'<img src=x onerror=alert(1)>',
            'steps'=>['<script>corrigir()</script>'], 'fields'=>['<NCM>'], 'item'=>2,
            'technical_message'=>'<xml>Retorno original</xml>', 'known_code'=>true,
            'category'=>'rejection', 'catalog_version'=>'2026.10.26',
            'sources'=>[
                ['title'=>'Fonte oficial','url'=>'https://www.nfe.fazenda.gov.br/portal/'],
                ['title'=>'Fonte externa','url'=>'https://www.nfe.fazenda.gov.br.example.com/rejeicao'],
                ['title'=>'Link inseguro','url'=>'javascript:alert(1)'],
            ],
        ];
        $html=view('fiscal.partials.sefaz-guidance',compact('guidance'))->render();
        self::assertStringContainsString('&lt;b&gt;Título&lt;/b&gt;',$html);
        self::assertStringContainsString('&lt;script&gt;corrigir()&lt;/script&gt;',$html);
        self::assertStringContainsString('href="https://www.nfe.fazenda.gov.br/portal/"',$html);
        self::assertStringNotContainsString('href="javascript:',$html);
        self::assertStringNotContainsString('href="https://www.nfe.fazenda.gov.br.example.com',$html);
        self::assertStringNotContainsString('<img src=x',$html);
        self::assertStringNotContainsString('<xml>',$html);
    }

    public function test_guidance_links_open_existing_get_registrations_for_the_subject_and_user_permissions(): void
    {
        $this->actingAs($this->admin());
        foreach([
            'accountant'=>[route('settings.index',['tab'=>'accounting'])],
            'authorized_xml'=>[route('settings.index',['tab'=>'accounting'])],
            'ncm'=>[route('products.index')],
            'cfop'=>[route('fiscal.tax-rules.index')],
            'rtc'=>[route('fiscal.tax-rules.index')],
            'taxpayer_identity'=>[route('customers.index'),route('settings.index',['tab'=>'general'])],
        ] as $category=>$urls){
            $guidance=$this->linkGuidance($category);
            $html=view('fiscal.partials.sefaz-guidance',compact('guidance'))->render();
            foreach($urls as $url)self::assertStringContainsString('href="'.$url.'"',$html);
            self::assertStringNotContainsString('<form',$html);
        }

        $operator=User::query()->create([
            'name'=>'Operador fiscal','email'=>'fiscal-only@example.com','password'=>'senhaSegura123',
            'role'=>'employee','is_active'=>true,'permissions'=>['fiscal'],
        ]);
        $this->actingAs($operator);
        foreach(['accountant','ncm','cfop','rtc','taxpayer_identity'] as $category){
            $guidance=$this->linkGuidance($category);
            $html=view('fiscal.partials.sefaz-guidance',compact('guidance'))->render();
            self::assertStringNotContainsString('sefaz-guidance-links',$html);
        }

        $operator->update(['permissions'=>['fiscal','customers']]);
        $guidance=$this->linkGuidance('taxpayer_identity');
        $html=view('fiscal.partials.sefaz-guidance',compact('guidance'))->render();
        self::assertStringContainsString('href="'.route('customers.index').'"',$html);
        self::assertStringNotContainsString('href="'.route('settings.index',['tab'=>'general']).'"',$html);
    }

    private function linkGuidance(string $category): array
    {
        return [
            'code'=>'778','title'=>'Confira o cadastro','summary'=>'Dados a revisar.',
            'steps'=>['Confira o campo indicado.'],'fields'=>[],'item'=>null,
            'technical_message'=>'Retorno original','known_code'=>true,'category'=>$category,
            'catalog_version'=>'2026.10.26','sources'=>[],
        ];
    }

    private function assertNoRetryButton(string $html): void
    {
        $dom=new \DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html,LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $buttons=(new \DOMXPath($dom))->query('//button[@form="nfeRetryForm"]');
        self::assertSame(0,$buttons->length,'Uma orientação não pode liberar o botão de retransmissão.');
    }
}
