<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Models\User;
use App\Services\Fiscal\IncomingNfeManifestationGateway;
use App\Services\Fiscal\IncomingNfeManifestationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class FiscalPurchasesManifestationTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function key(): string
    {
        return '29261099887766000122550010000001231000001234';
    }

    private function user(): User
    {
        return User::query()->create([
            'name'=>'Conferência Fiscal','email'=>'manifest-tester@example.test',
            'password'=>'SenhaSegura123','role'=>'admin','is_active'=>true,
        ]);
    }

    private function invoice(): IncomingNfe
    {
        return IncomingNfe::query()->create(['access_key'=>$this->key(),'status'=>'summary']);
    }

    private function ready(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('fiscal/certificates/manifest.pfx','fixture');
        CompanySetting::current()->update([
            'certificate_path'=>'fiscal/certificates/manifest.pfx',
            'certificate_password'=>'SenhaDeTeste',
            'certificate_expires_at'=>now()->addYear(),
        ]);
    }

    private function fakeGateway(string $response, bool $throw=false): object
    {
        $fake=new class($response,$throw) extends IncomingNfeManifestationGateway {
            public int $calls=0;
            public array $last=[];
            public function __construct(private string $response,private bool $throw) {}
            public function send(CompanySetting $company,string $cnpj,string $key,string $event,?string $reason): string
            {
                $this->calls++;
                $this->last=[$cnpj,$key,$event,$reason];
                if($this->throw) throw new RuntimeException('Falha simulada de rede');
                return $this->response;
            }
        };
        app()->instance(IncomingNfeManifestationGateway::class,$fake);
        return $fake;
    }

    private function acceptedResponse(string $event='210210'): string
    {
        return "[Evento]\nidLote=1\ncStat=128\n".
            "[EVENTO001]\ntpAmb=1\nCStat=135\nxMotivo=Evento registrado e vinculado a NF-e\n".
            "chDFe=".$this->key()."\nnProt=129260000123456\n".
            "tpEvento=".$event."\nnSeqEvento=1\n";
    }

    public function test_acbr_event_ini_uses_production_recipient_and_correct_event_codes(): void
    {
        $gateway=new IncomingNfeManifestationGateway();
        $ini=$gateway->eventIni('12345678000199',$this->key(),'210240','Mercadoria não entregue');
        self::assertStringContainsString("cOrgao=91\nCNPJ=12345678000199",$ini);
        self::assertStringContainsString("chNFe=".$this->key(),$ini);
        self::assertStringContainsString("tpEvento=210240\nnSeqEvento=1\nversaoEvento=1.00",$ini);
        self::assertStringContainsString('xJust=Mercadoria não entregue',$ini);
        self::assertStringNotContainsString('xJust=', $gateway->eventIni('12345678000199',$this->key(),'210210',null));
    }

    public function test_real_event_response_protocol_is_required_before_saving_a_manifestation(): void
    {
        $this->ready();
        $invoice=$this->invoice();
        $fake=$this->fakeGateway($this->acceptedResponse());
        $this->actingAs($this->user())
            ->post(route('fiscal.purchases.manifest',$invoice),['event_type'=>'210210'])
            ->assertRedirect()->assertSessionHas('success');

        self::assertSame(1,$fake->calls);
        self::assertSame(['12345678000199',$this->key(),'210210',null],$fake->last);
        self::assertSame('210210',$invoice->fresh()->manifestation);
        self::assertSame('129260000123456',$invoice->fresh()->manifestation_protocol);
        $this->assertDatabaseHas('incoming_nfe_manifestation_attempts',[
            'incoming_nfe_id'=>$invoice->id,'event_code'=>'210210',
            'status'=>'accepted','sefaz_status'=>'135','protocol'=>'129260000123456',
        ]);
        $this->assertDatabaseCount('stock_movements',0);
        $this->assertDatabaseCount('products',0);

        $this->post(route('fiscal.purchases.manifest',$invoice),['event_type'=>'210210'])
            ->assertSessionHas('success');
        self::assertSame(1,$fake->calls);
        $this->assertDatabaseCount('incoming_nfe_manifestation_attempts',1);
    }

    public function test_rejection_is_not_reported_as_authorized_and_may_be_corrected(): void
    {
        $this->ready();
        $invoice=$this->invoice();
        $fake=$this->fakeGateway("[EVENTO001]\ntpAmb=1\nCStat=595\nxMotivo=Justificativa ausente\n");
        $this->actingAs($this->user())
            ->post(route('fiscal.purchases.manifest',$invoice),['event_type'=>'210210'])
            ->assertSessionHas('error');
        self::assertNull($invoice->fresh()->manifestation);
        $this->assertDatabaseHas('incoming_nfe_manifestation_attempts',[
            'incoming_nfe_id'=>$invoice->id,'status'=>'rejected','sefaz_status'=>'595',
        ]);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_timeout_is_uncertain_and_never_retransmitted(): void
    {
        $this->ready();
        $invoice=$this->invoice();
        $fake=$this->fakeGateway('',true);
        $this->actingAs($this->user())
            ->post(route('fiscal.purchases.manifest',$invoice),['event_type'=>'210200'])
            ->assertSessionHas('error');
        $this->post(route('fiscal.purchases.manifest',$invoice),['event_type'=>'210200'])
            ->assertSessionHas('error');
        self::assertSame(1,$fake->calls);
        self::assertNull($invoice->fresh()->manifestation);
        $this->assertDatabaseHas('incoming_nfe_manifestation_attempts',[
            'incoming_nfe_id'=>$invoice->id,'status'=>'uncertain',
        ]);
    }

    public function test_mismatched_response_is_not_accepted_as_a_valid_sefaz_protocol(): void
    {
        $service=app(IncomingNfeManifestationService::class);
        foreach ([
            str_replace('tpAmb=1','tpAmb=2',$this->acceptedResponse()),
            str_replace($this->key(),'35261099887766000122550010000001231000001234',$this->acceptedResponse()),
            str_replace('tpEvento=210210','tpEvento=210220',$this->acceptedResponse()),
            str_replace('nProt=129260000123456','nProt=',$this->acceptedResponse()),
            "[Evento]\nCStat=128\n",
        ] as $response) {
            self::assertSame('uncertain',$service->parseResponse($response,$this->key(),'210210')['status']);
        }
    }

    public function test_operation_not_realized_requires_reason_and_does_not_transmit_without_it(): void
    {
        $this->ready();
        $invoice=$this->invoice();
        $fake=$this->fakeGateway($this->acceptedResponse('210240'));
        $this->actingAs($this->user())
            ->post(route('fiscal.purchases.manifest',$invoice),[
                'event_type'=>'210240','reason'=>'curta',
            ])->assertSessionHasErrors('reason');
        self::assertSame(0,$fake->calls);
        $this->post(route('fiscal.purchases.manifest',$invoice),[
            'event_type'=>'210240','reason'=>'Mercadoria não foi entregue',
        ])->assertSessionHas('success');
        self::assertSame('210240',$invoice->fresh()->manifestation);
    }

    public function test_purchases_table_exposes_actual_manifest_column_and_post_form(): void
    {
        $this->actingAs($this->user());
        $invoice=$this->invoice();
        $this->get(route('fiscal.purchases.index'))
            ->assertOk()->assertSee('Manifesto')
            ->assertSee('Ciência da operação')
            ->assertSee('Operação não realizada')
            ->assertSee(route('fiscal.purchases.manifest',$invoice));
        $this->get(route('fiscal.purchases.show',$invoice))->assertOk()->assertSee('Sem manifesto');
    }

    public function test_unprivileged_users_cannot_send_recipient_events(): void
    {
        $invoice=$this->invoice();
        $user=User::query()->create([
            'name'=>'Sem Fiscal','email'=>'manifest-blocked@example.test',
            'password'=>'SenhaSegura123','role'=>'operator','permissions'=>['pdv'],
            'is_active'=>true,
        ]);
        $this->actingAs($user)->post(route('fiscal.purchases.manifest',$invoice),[
            'event_type'=>'210200',
        ])->assertForbidden();
        $this->assertDatabaseCount('incoming_nfe_manifestation_attempts',0);
    }
}
