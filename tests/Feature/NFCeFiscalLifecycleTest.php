<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\NFCeInutilization;
use App\Services\Fiscal\NFCeCancellationService;
use App\Services\Fiscal\NFCeFiscalEventResponse;
use App\Services\Fiscal\NFCeInutilizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class NFCeFiscalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function issuer(): void
    {
        CompanySetting::query()->create([
            'legal_name'=>'Emissor teste','document'=>'39323356000100',
            'state'=>'BA','crt'=>'1','state_registration'=>'172036473',
        ]);
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfce','enabled',true);
        AppSetting::put('nfce','environment','homologation');
        AppSetting::put('nfce','series',1);
        AppSetting::put('nfce','next_number',100);
    }

    public function test_cancellation_requires_authorized_key_protocol_and_bahia_deadline(): void
    {
        $this->issuer();
        $job=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','status'=>'authorized',
            'environment'=>'homologation','series'=>1,'document_number'=>10,
            'access_key'=>'29261039323356000100650010000000101234567890',
            'protocol'=>'129260000000001','authorized_at'=>now()->subMinutes(29),
        ]);
        $service=app(NFCeCancellationService::class);
        $service->request($job,'Operação desfeita antes da saída do produto.');
        self::assertSame('pending',$job->fresh()->cancellation_status);
        self::assertSame('authorized',$job->fresh()->status);
        try {
            $service->request($job,'Repetição indevida do cancelamento.');
            self::fail('A segunda solicitação deve ser bloqueada.');
        }catch(ValidationException){self::assertSame('pending',$job->fresh()->cancellation_status);}

        $job->update(['cancellation_status'=>null,'authorized_at'=>now()->subMinutes(31)]);
        $this->expectException(ValidationException::class);
        $service->request($job,'Operação desfeita sem saída de mercadoria.');
    }

    public function test_cancellation_only_accepts_matching_sefaz_key_and_protocol(): void
    {
        $parser=new NFCeFiscalEventResponse();
        $key=str_repeat('7',44);
        $result=$parser->parse("[Cancelamento]\r\nCStat=135\r\nNProt=129260000000001\r\nTpAmb=2\r\nchDFe={$key}\r\nXMotivo=Evento registrado\r\n",'cancelamento');
        self::assertTrue($parser->cancellationAccepted($result,$key,'homologation'));
        self::assertFalse($parser->cancellationAccepted($result,str_repeat('1',44),'homologation'));
        self::assertFalse($parser->cancellationAccepted($result,$key,'production'));
        $result['protocol']='';
        self::assertFalse($parser->cancellationAccepted($result,$key,'homologation'));
        $rejected=$parser->parse("[Cancelamento]\nCStat=573\nTpAmb=2\nchDFe={$key}\n",'cancelamento');
        self::assertFalse($parser->cancellationAccepted($rejected,$key,'homologation'));
    }

    public function test_inutilization_only_allows_unused_gap_before_next_number(): void
    {
        $this->issuer();
        FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','status'=>'rejected',
            'environment'=>'homologation','series'=>1,'document_number'=>15,
        ]);
        $service=app(NFCeInutilizationService::class);
        $this->expectException(ValidationException::class);
        $service->request([
            'year'=>2026,'series'=>1,'first_number'=>14,'last_number'=>16,
            'reason'=>'Intervalo sem emissão por falha técnica.',
        ],1);
    }

    public function test_inutilization_tracks_single_reserved_range_without_a_network_call(): void
    {
        $this->issuer();
        $service=app(NFCeInutilizationService::class);
        $record=$service->request([
            'year'=>2026,'series'=>1,'first_number'=>20,'last_number'=>23,
            'reason'=>'Lacuna comprovada na sequência por problema técnico.',
        ],1);
        self::assertSame('pending',$record->status);
        self::assertSame(1,NFCeInutilization::query()->count());

        try{
            $service->request([
                'year'=>2026,'series'=>1,'first_number'=>22,'last_number'=>24,
                'reason'=>'Tentativa de reutilizar uma faixa já enviada.',
            ],1);
            self::fail('Faixas sobrepostas não podem ser solicitadas.');
        }catch(ValidationException){
            self::assertSame(1,NFCeInutilization::query()->count());
        }
    }

    public function test_inutilization_confirms_only_sefaz_102_with_protocol(): void
    {
        $parser=new NFCeFiscalEventResponse();
        $data=$parser->parse("[Inutilizacao]\nCStat=102\nTpAmb=2\nNProt=129260000000002\nXMotivo=Inutilização homologada\n",'inutilizacao');
        self::assertTrue($parser->inutilizationAccepted($data,'homologation'));
        $data['protocol']='';
        self::assertFalse($parser->inutilizationAccepted($data,'homologation'));
        $data['protocol']='129260000000002';
        self::assertFalse($parser->inutilizationAccepted($data,'production'));
    }
}
