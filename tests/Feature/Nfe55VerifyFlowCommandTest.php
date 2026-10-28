<?php

namespace Tests\Feature;

use App\Jobs\ProcessNfe55Job;
use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Services\Fiscal\Nfe55DanfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class Nfe55VerifyFlowCommandTest extends TestCase
{
    use RefreshDatabase, WithConfiguredCompany;

    private function draft(array $attributes = []): NfeDraft
    {
        Bus::fake();
        Storage::fake('local');
        return NfeDraft::query()->create(array_replace([
            'status' => 'draft', 'environment' => 'homologation',
            'issue_date' => now()->toDateString(), 'series' => 1,
        ], $attributes));
    }

    private function authorizedFixture(): NfeDraft
    {
        $draft = $this->draft([
            'status' => 'authorized', 'document_number' => 42,
            'access_key' => '29261012345678000199550010000000421000001234',
            'protocol' => '129260001234567', 'authorized_at' => now(),
        ]);
        $path = 'fiscal/nfe55/'.$draft->id.'/authorized.xml';
        $draft->update(['xml_path' => $path]);
        // Deliberately synthetic local XML. Only PDF rendering is stubbed;
        // the existing DANFE service still verifies every XML/protocol match.
        $xml = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<NFe><infNFe Id="NFe'.$draft->access_key.'"><ide><mod>55</mod><tpAmb>2</tpAmb></ide>'
            .'<emit><CNPJ>12345678000199</CNPJ></emit></infNFe></NFe>'
            .'<protNFe><infProt><tpAmb>2</tpAmb><chNFe>'.$draft->access_key.'</chNFe>'
            .'<nProt>'.$draft->protocol.'</nProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
        Storage::disk('local')->put($path, $xml);
        app()->instance(Nfe55DanfeService::class, new class extends Nfe55DanfeService {
            protected function render(string $xml, CompanySetting $company): string
            {
                return "%PDF-1.4\nSynthetic local test DANFE";
            }
        });
        return $draft;
    }

    public function test_production_is_refused_before_queue_consultation_or_artifact_creation(): void
    {
        $draft = $this->draft(['environment' => 'production']);
        $this->artisan('nfe:verify-flow', ['id' => $draft->id, '--enqueue' => true, '--process' => true, '--consult' => true])
            ->expectsOutputToContain('Produção recusada')->assertExitCode(1);
        self::assertSame([], Storage::disk('local')->allFiles());
        self::assertSame('draft', $draft->fresh()->status);
        self::assertNull($draft->fresh()->document_number);
        Bus::assertNotDispatched(ProcessNfe55Job::class);
    }

    public function test_reserved_documents_are_never_enqueued_again(): void
    {
        foreach (['queued', 'processing', 'pending', 'prepared', 'rejected', 'cancelled'] as $index => $status) {
            $number = 42 + $index;
            $draft = $this->draft(['status' => $status, 'document_number' => $number]);
            $this->artisan('nfe:verify-flow', ['id' => $draft->id, '--enqueue' => true])
                ->expectsOutputToContain('nenhuma nova emissão foi agendada')
                ->assertExitCode(in_array($status, ['prepared', 'rejected', 'cancelled'], true) ? 1 : 2);
            self::assertSame($number, $draft->fresh()->document_number);
            self::assertSame($status, $draft->fresh()->status);
            Bus::assertNotDispatched(ProcessNfe55Job::class);
        }
    }

    public function test_default_preflight_requires_existing_validation_and_reserves_nothing(): void
    {
        $draft = $this->draft();
        $this->artisan('nfe:verify-flow', ['id' => $draft->id])
            ->expectsOutputToContain('Salve e valide a NF-e')->assertExitCode(1);
        self::assertNull($draft->fresh()->document_number);
        self::assertFalse(json_decode(Storage::disk('local')->get('fiscal/nfe55/'.$draft->id.'/verification.json'), true)['full_flow_verified']);
        Bus::assertNotDispatched(ProcessNfe55Job::class);
    }

    public function test_enqueue_and_consult_begin_with_queue_validation_without_reserving_on_failure(): void
    {
        $draft = $this->draft(['validated_at' => now()]);
        AppSetting::put('fiscal', 'enabled', false);
        AppSetting::put('nfe', 'enabled', false);
        $this->artisan('nfe:verify-flow', ['id' => $draft->id, '--enqueue' => true, '--consult' => true])
            ->expectsOutputToContain('Emissão fiscal desativada nas configurações.')
            ->doesntExpectOutputToContain('Consulta exige estado permitido')
            ->assertExitCode(1);
        self::assertNull($draft->fresh()->document_number);
        self::assertNull($draft->fresh()->access_key);
        self::assertSame('draft', $draft->fresh()->status);
        Bus::assertNotDispatched(ProcessNfe55Job::class);
    }

    public function test_authorized_xml_and_protocol_are_checked_and_private_pdf_and_summary_are_preserved(): void
    {
        $draft = $this->authorizedFixture();
        $this->artisan('nfe:verify-flow', ['id' => $draft->id, '--enqueue' => true])
            ->expectsOutputToContain('XML/protocolo conferidos e DANFE gerado')->assertExitCode(0);
        $base = 'fiscal/nfe55/'.$draft->id;
        $pdf = Storage::disk('local')->get($base.'/verified-danfe.pdf');
        $summary = json_decode(Storage::disk('local')->get($base.'/verification.json'), true);
        self::assertTrue($summary['full_flow_verified']);
        self::assertSame('authorized', $summary['status']);
        self::assertSame(hash('sha256', $pdf), $summary['danfe_sha256']);
        self::assertContains('authorized_xml_protocol_verified', $summary['stages']);
        self::assertContains('danfe_generated', $summary['stages']);
        self::assertStringNotContainsString('<nfeProc', json_encode($summary));
        Bus::assertNotDispatched(ProcessNfe55Job::class);
    }

    public function test_mismatched_protocol_and_dtd_never_produce_verified_danfe(): void
    {
        $draft = $this->authorizedFixture();
        $draft->update(['protocol' => '129260001234568']);
        $this->artisan('nfe:verify-flow', ['id' => $draft->id])->assertExitCode(1);
        Storage::disk('local')->assertMissing('fiscal/nfe55/'.$draft->id.'/verified-danfe.pdf');
        $draft->update(['protocol' => '129260001234567']);
        $xml = Storage::disk('local')->get($draft->xml_path);
        Storage::disk('local')->put($draft->xml_path, '<!DOCTYPE nfeProc>'.$xml);
        $this->artisan('nfe:verify-flow', ['id' => $draft->id])->assertExitCode(1);
        Storage::disk('local')->assertMissing('fiscal/nfe55/'.$draft->id.'/verified-danfe.pdf');
    }

    public function test_consultation_requires_existing_key_and_allowed_status(): void
    {
        $draft = $this->draft();
        $this->artisan('nfe:verify-flow', ['id' => $draft->id, '--consult' => true])
            ->expectsOutputToContain('Consulta exige estado permitido e chave fiscal existente')->assertExitCode(1);
        self::assertNull($draft->fresh()->access_key);
        Bus::assertNotDispatched(ProcessNfe55Job::class);
    }

    public function test_wait_has_an_explicit_zero_to_300_seconds_limit(): void
    {
        $draft = $this->draft();
        foreach (['-1', '301', '2.5'] as $wait) {
            $this->artisan('nfe:verify-flow', ['id' => $draft->id, '--wait' => $wait])
                ->expectsOutputToContain('--wait entre 0 e 300')->assertExitCode(1);
        }
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_targeted_processing_uses_the_existing_claim_without_consuming_other_fiscal_jobs(): void
    {
        $draft=$this->draft(['status'=>'queued','document_number'=>42]);
        $other=NfeDraft::query()->create(['status'=>'queued','environment'=>'production','series'=>1,'document_number'=>43,'issue_date'=>now()->toDateString()]);
        // A real database queue entry for an unrelated document must stay untouched.
        // Queue directly because the fixture's Bus facade intentionally records dispatches.
        app('queue')->connection('database')->push((new ProcessNfe55Job($draft->id))->onQueue('fiscal'),'','fiscal');
        app('queue')->connection('database')->push((new ProcessNfe55Job($other->id))->onQueue('fiscal'),'','fiscal');
        $before=\Illuminate\Support\Facades\DB::table('jobs')->count();
        $this->artisan('nfe:verify-flow',['id'=>$draft->id,'--process'=>true])
            ->expectsOutputToContain('Processamento fiscal da NF-e '.$draft->id.' concluído')
            ->assertExitCode(1);
        self::assertSame('prepared',$draft->fresh()->status);
        self::assertNull($draft->fresh()->transmission_attempted_at);
        self::assertSame('queued',$other->fresh()->status);
        self::assertSame($before,\Illuminate\Support\Facades\DB::table('jobs')->count());
        $summary=json_decode(Storage::disk('local')->get('fiscal/nfe55/'.$draft->id.'/verification.json'),true);
        self::assertFalse($summary['full_flow_verified']);
        self::assertContains('target_fiscal_processing_completed',$summary['stages']);
        (new ProcessNfe55Job($draft->id))->handle(app(\App\Services\Fiscal\Nfe55EmissionService::class));
        self::assertSame('prepared',$draft->fresh()->status);
        self::assertNull($draft->fresh()->transmission_attempted_at);
    }

    public function test_targeted_processing_preserves_pending_transmission_and_never_sends_again(): void
    {
        $attempt=now()->startOfSecond();
        $draft=$this->draft(['status'=>'pending','document_number'=>42,'transmission_attempted_at'=>$attempt]);
        $this->artisan('nfe:verify-flow',['id'=>$draft->id,'--enqueue'=>true,'--process'=>true])
            ->expectsOutputToContain('nenhuma nova emissão foi agendada')->assertExitCode(2);
        self::assertSame('pending',$draft->fresh()->status);
        self::assertTrue($attempt->equalTo($draft->fresh()->transmission_attempted_at));
        Bus::assertNotDispatched(ProcessNfe55Job::class);
    }
}
