<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Services\Fiscal\ACBr\RecipientEventPreparationException;
use App\Services\Fiscal\IncomingNfeManifestationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class RecipientManifestationDoctorTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function ready(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('fiscal/certificates/manifest-test.pfx','fixture');
        CompanySetting::current()->update([
            'certificate_path'=>'fiscal/certificates/manifest-test.pfx',
            'certificate_password'=>'testing-only',
            'certificate_expires_at'=>now()->addYear(),
        ]);
    }

    private function invoice(): IncomingNfe
    {
        return IncomingNfe::query()->create([
            'access_key'=>'29261099887766000122550010000001231000001234',
            'status'=>'summary',
        ]);
    }

    public function test_dry_run_loads_same_recipient_event_without_transmission_or_database_change(): void
    {
        $this->ready();
        $invoice=$this->invoice();
        $gateway=new class extends IncomingNfeManifestationGateway {
            public int $preflights=0;
            public int $transmissions=0;
            public function diagnose(CompanySetting $company,string $cnpj,string $key,string $event): void
            {
                $this->preflights++;
                \PHPUnit\Framework\Assert::assertSame('12345678000199',$cnpj);
                \PHPUnit\Framework\Assert::assertSame('210210',$event);
                \PHPUnit\Framework\Assert::assertSame('29261099887766000122550010000001231000001234',$key);
            }
            public function send(CompanySetting $company,string $cnpj,string $key,string $event,?string $reason): string
            {
                $this->transmissions++;
                throw new \RuntimeException('Dry run must never send to SEFAZ');
            }
        };
        app()->instance(IncomingNfeManifestationGateway::class,$gateway);
        self::assertSame(0,Artisan::call('fiscal:manifestation-doctor',['invoice'=>$invoice->id]));
        self::assertSame(1,$gateway->preflights);
        self::assertSame(0,$gateway->transmissions);
        self::assertNull($invoice->fresh()->manifestation);
        $this->assertDatabaseCount('incoming_nfe_manifestation_attempts',0);
    }

    public function test_dry_run_handles_native_failure_without_exposing_private_acbr_data(): void
    {
        $this->ready();
        $invoice=$this->invoice();
        $gateway=new class extends IncomingNfeManifestationGateway {
            public function diagnose(CompanySetting $company,string $cnpj,string $key,string $event): void
            {
                throw new RecipientEventPreparationException(
                    'ACBr NFE_CarregarEventoINI falhou (-10): arquivo C:\certificados\senha-secreta.pfx');
            }
        };
        app()->instance(IncomingNfeManifestationGateway::class,$gateway);
        self::assertSame(1,Artisan::call('fiscal:manifestation-doctor',['invoice'=>$invoice->id]));
        $output=Artisan::output();
        self::assertStringContainsString('INI do evento', $output);
        self::assertStringContainsString('código -10', $output);
        self::assertStringNotContainsString('senha-secreta',$output);
        self::assertStringNotContainsString('certificados',$output);
        self::assertNull($invoice->fresh()->manifestation);
        $this->assertDatabaseCount('incoming_nfe_manifestation_attempts',0);
    }

    public function test_diagnostic_does_not_guess_date_error_from_filename(): void
    {
        $this->ready();
        $invoice=$this->invoice();
        $gateway=new class extends IncomingNfeManifestationGateway {
            public function diagnose(CompanySetting $company,string $cnpj,string $key,string $event): void
            {
                throw new RecipientEventPreparationException(
                    'ACBr NFE_CarregarEventoINI falhou (-10): arquivo datafile.ini inválido');
            }
        };
        app()->instance(IncomingNfeManifestationGateway::class,$gateway);
        self::assertSame(1,Artisan::call('fiscal:manifestation-doctor',['invoice'=>$invoice->id]));
        $output=Artisan::output();
        self::assertStringContainsString('código -10',$output);
        self::assertStringNotContainsString('formato de data/hora',$output);
        self::assertStringNotContainsString('datafile.ini',$output);
        $this->assertDatabaseCount('incoming_nfe_manifestation_attempts',0);
    }

    public function test_dry_run_does_not_allow_invalid_event_or_missing_invoice(): void
    {
        $this->ready();
        self::assertSame(1,Artisan::call('fiscal:manifestation-doctor',
            ['invoice'=>999999,'--event'=>'210210']));
        $invoice=$this->invoice();
        self::assertSame(1,Artisan::call('fiscal:manifestation-doctor',
            ['invoice'=>$invoice->id,'--event'=>'210240']));
        $this->assertDatabaseCount('incoming_nfe_manifestation_attempts',0);
    }

    public function test_event_loader_uses_real_private_ini_file_and_cleans_it(): void
    {
        $code=file_get_contents(app_path('Services/Fiscal/ACBr/ACBrNFeService.php'));
        self::assertStringContainsString("'manifest-'.bin2hex(random_bytes(12)).'.ini'",$code);
        self::assertStringContainsString("str_replace(\"\\n\",\"\\r\\n\",", $code);
        self::assertStringContainsString("NFE_CarregarEventoINI',[\$path]",$code);
        self::assertStringContainsString("if (is_file(\$path)) @unlink(\$path)",$code);
        $gateway=file_get_contents(app_path('Services/Fiscal/IncomingNfeManifestationGateway.php'));
        self::assertStringContainsString('prepareRecipientEvent($payload)', $gateway);
    }
}
