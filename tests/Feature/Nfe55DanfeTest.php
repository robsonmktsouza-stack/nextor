<?php
namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\User;
use App\Services\Fiscal\Nfe55DanfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class Nfe55DanfeTest extends TestCase
{
    use RefreshDatabase,WithConfiguredCompany;

    public function test_download_print_only_from_matching_authorized_xml(): void
    {
        Storage::fake('local');
        $key='29261012345678000100550010000001231000001234';
        $protocol='129260001234567';
        CompanySetting::current()->update(['document'=>'12345678000100','state'=>'BA']);
        $user=User::query()->create([
            'name'=>'Emissor','email'=>'danfe55@example.test',
            'password'=>'password','role'=>'admin','is_active'=>true,
        ]);
        $draft=NfeDraft::query()->create([
            'status'=>'authorized','issue_date'=>'2026-10-23','document_number'=>123,
            'series'=>1,'access_key'=>$key,'protocol'=>$protocol,
            'xml_path'=>'fiscal/nfe55/1/authorized.xml',
        ]);
        $path='fiscal/nfe55/'.$draft->id.'/authorized.xml';
        $draft->update(['xml_path'=>$path]);
        $xml='<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
            .'<NFe><infNFe Id="NFe'.$key.'"><ide><mod>55</mod></ide><emit>'
            .'<CNPJ>12345678000100</CNPJ></emit></infNFe></NFe>'
            .'<protNFe><infProt><chNFe>'.$key.'</chNFe><nProt>'.$protocol.'</nProt>'
            .'<cStat>100</cStat></infProt></protNFe></nfeProc>';
        Storage::disk('local')->put($path,$xml);
        app()->instance(Nfe55DanfeService::class,new class extends Nfe55DanfeService {
            protected function render(string $xml,CompanySetting $company): string {
                return "%PDF-1.4\nmock-danfe";
            }
        });
        $this->actingAs($user)->get(route('fiscal.nfe.danfe',$draft))
            ->assertOk()->assertHeader('Content-Type','application/pdf')
            ->assertHeader('X-Content-Type-Options','nosniff');
        $this->get(route('fiscal.nfe.xml',$draft))
            ->assertOk()->assertDownload();

        $draft->update(['protocol'=>'129260001234568']);
        $this->get(route('fiscal.nfe.danfe',$draft))->assertStatus(409);
        $draft->update(['protocol'=>$protocol,'status'=>'pending']);
        $this->get(route('fiscal.nfe.danfe',$draft))->assertStatus(409);
        $draft->update(['status'=>'authorized','xml_path'=>'fiscal/nfe55/'.$draft->id.'/signed.xml']);
        $this->get(route('fiscal.nfe.danfe',$draft))->assertStatus(409);
    }
}
