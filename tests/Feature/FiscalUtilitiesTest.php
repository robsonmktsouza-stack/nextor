<?php

namespace Tests\Feature;

use App\Models\FiscalDocumentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FiscalUtilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Operador fiscal',
            'email'=>'utilities-test@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    private function document(string $type, string $file='authorized.xml', string $status='authorized'): FiscalDocumentJob
    {
        $doc=FiscalDocumentJob::query()->create([
            'document_type'=>$type,
            'status'=>$status,
            'environment'=>'homologation',
            'series'=>1,
            'document_number'=>120,
            'prepared_at'=>now(),
        ]);
        if ($file!=='') {
            $path='fiscal/'.$type.'/'.$doc->id.'/'.$file;
            Storage::disk('local')->put($path,'<?xml version="1.0"?><documento/>');
            $doc->update(['xml_path'=>$path]);
        }
        return $doc->fresh();
    }

    public function test_all_fiscal_tabs_display_utilities_actions(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        foreach (['nfe','nfce','nfse','cte','mdfe'] as $tab) {
            $this->get(route('fiscal.index',['tab'=>$tab]))
                ->assertOk()
                ->assertSee('Utilitários')
                ->assertSee('Baixar XML')
                ->assertSee('data-fiscal-download-auxiliary',false);
        }
    }

    public function test_authorized_nfce_xml_is_downloaded_from_private_storage(): void
    {
        Storage::fake('local');
        $doc=$this->document('nfce');
        $this->actingAs($this->admin())
            ->post(route('fiscal.utilities.xml'),[
                'document_type'=>'nfce','ids'=>[$doc->id],
            ])
            ->assertOk()
            ->assertDownload('NFCe-S1-N120-'.$doc->id.'.xml');
    }

    public function test_document_from_another_tab_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        $doc=$this->document('nfce');
        $this->actingAs($this->admin())
            ->from(route('fiscal.index',['tab'=>'nfe']))
            ->post(route('fiscal.utilities.xml'),[
                'document_type'=>'nfe','ids'=>[$doc->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_unsigned_and_missing_xml_are_not_misrepresented_as_authorized(): void
    {
        Storage::fake('local');
        $doc=$this->document('nfce','signed.xml','authorized');
        $this->actingAs($this->admin())
            ->from(route('fiscal.index',['tab'=>'nfce']))
            ->post(route('fiscal.utilities.xml'),[
                'document_type'=>'nfce','ids'=>[$doc->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_authorized_nfce_can_open_existing_danfe(): void
    {
        Storage::fake('local');
        $doc=$this->document('nfce');
        $this->actingAs($this->admin())
            ->get(route('fiscal.utilities.auxiliary',$doc))
            ->assertRedirect(route('fiscal.nfce.danfe',$doc));
    }

    public function test_nfse_downloads_archived_danfse_pdf_without_generating_fake_document(): void
    {
        Storage::fake('local');
        $doc=$this->document('nfse');
        Storage::disk('local')->put('fiscal/nfse/'.$doc->id.'/danfse.pdf','%PDF-dummy');
        $this->actingAs($this->admin())
            ->get(route('fiscal.utilities.auxiliary',$doc))
            ->assertOk()
            ->assertHeader('Content-Type','application/pdf')
            ->assertHeader('Content-Disposition','inline; filename="NFSe-S1-N120-'.$doc->id.'.pdf"');
    }

    public function test_without_pdf_other_models_do_not_offer_fake_auxiliary_document(): void
    {
        Storage::fake('local');
        $doc=$this->document('cte');
        $this->actingAs($this->admin())
            ->from(route('fiscal.index',['tab'=>'cte']))
            ->get(route('fiscal.utilities.auxiliary',$doc))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_xml_zip_contains_selected_authorized_documents(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP ZIP extension is unavailable.');
        }
        Storage::fake('local');
        $a=$this->document('nfce');
        $b=$this->document('nfce');
        $this->actingAs($this->admin())
            ->post(route('fiscal.utilities.xml'),[
                'document_type'=>'nfce','ids'=>[$a->id,$b->id],
            ])
            ->assertOk()
            ->assertHeader('Content-Type','application/zip');
    }
}
