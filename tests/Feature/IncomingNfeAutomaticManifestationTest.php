<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Services\Fiscal\IncomingNfeManifestationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class IncomingNfeAutomaticManifestationTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function ready(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('fiscal/certificates/auto.pfx','test fixture');
        CompanySetting::current()->update([
            'certificate_path'=>'fiscal/certificates/auto.pfx',
            'certificate_password'=>'test only',
            'certificate_expires_at'=>now()->addYear(),
        ]);
    }

    private function pending(string $key): IncomingNfe
    {
        $invoice=IncomingNfe::query()->create(['access_key'=>$key,'status'=>'summary']);
        $invoice->manifestationAttempts()->create(['event_code'=>'210210','status'=>'uncertain']);
        return $invoice;
    }

    public function test_automatic_check_is_read_only_and_respects_global_cooldown(): void
    {
        $this->ready();
        $first=$this->pending('29261099887766000122550010000001231000001234');
        $second=$this->pending('29261099887766000122550010000001231000004567');
        $fake=new class extends IncomingNfeManifestationGateway {
            public int $consultations=0;
            public int $transmissions=0;
            public function consult(CompanySetting $company,string $cnpj,string $key): string
            {
                $this->consultations++;
                return "[CONSULTA]\nCStat=100\n"; // authorization of document is NOT proof of manifestation
            }
            public function send(CompanySetting $company,string $cnpj,string $key,string $event,?string $reason): string
            {
                $this->transmissions++;
                throw new \RuntimeException('Scheduled job must not send events');
            }
        };
        app()->instance(IncomingNfeManifestationGateway::class,$fake);
        self::assertSame(0,Artisan::call('fiscal:verify-incoming-manifestations'));
        self::assertSame(0,Artisan::call('fiscal:verify-incoming-manifestations'));
        self::assertSame(1,$fake->consultations);
        self::assertSame(0,$fake->transmissions);
        self::assertNull($first->fresh()->manifestation);
        self::assertNull($second->fresh()->manifestation);
        $this->assertDatabaseCount('incoming_nfe_manifestation_attempts',2);
        self::assertNotNull($first->manifestationAttempts()->first()->verification_checked_at);
        self::assertNull($second->manifestationAttempts()->first()->verification_checked_at);

        $this->travel(66)->minutes();
        self::assertSame(0,Artisan::call('fiscal:verify-incoming-manifestations'));
        self::assertSame(2,$fake->consultations);
        self::assertNotNull($second->manifestationAttempts()->first()->verification_checked_at);
        self::assertSame(0,$fake->transmissions);
    }

    public function test_scheduler_does_not_consult_without_certificate_or_pending_event(): void
    {
        $fake=new class extends IncomingNfeManifestationGateway {
            public int $calls=0;
            public function consult(CompanySetting $company,string $cnpj,string $key): string
            {
                $this->calls++;
                throw new \RuntimeException('Unexpected SEFAZ request');
            }
        };
        app()->instance(IncomingNfeManifestationGateway::class,$fake);
        $this->pending('29261099887766000122550010000001231000001234');
        self::assertSame(0,Artisan::call('fiscal:verify-incoming-manifestations'));
        self::assertSame(0,$fake->calls);
        $this->ready();
        \App\Models\IncomingNfeManifestationAttempt::query()->delete();
        self::assertSame(0,Artisan::call('fiscal:verify-incoming-manifestations'));
        self::assertSame(0,$fake->calls);
    }

    public function test_purchase_list_does_not_expose_manual_verification_and_has_three_actions(): void
    {
        $html=file_get_contents(resource_path('views/fiscal/purchases/index.blade.php'));
        $show=file_get_contents(resource_path('views/fiscal/purchases/show.blade.php'));
        self::assertStringNotContainsString('fiscal-manifest-verify-button',$html);
        self::assertStringNotContainsString('route(\'fiscal.purchases.manifest.verify\'',$html);
        self::assertStringNotContainsString('route(\'fiscal.purchases.manifest.verify\'',$show);
        self::assertStringContainsString('Conferência automática em andamento',$html);
        self::assertStringContainsString('route(\'fiscal.purchases.xml\'',$html);
        self::assertStringContainsString('route(\'fiscal.purchases.danfe\'',$html);
        self::assertStringContainsString('Valor total',$html);
        self::assertStringContainsString('filteredTotal',$html);
        self::assertStringContainsString('colgroup',$html);
        $routes=file_get_contents(base_path('routes/console.php'));
        self::assertStringContainsString('fiscal:verify-incoming-manifestations',$routes);
    }
}
