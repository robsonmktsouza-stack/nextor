<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\Nfe55Inutilization;
use App\Models\OperationNature;
use App\Models\User;
use App\Services\Fiscal\FiscalDocumentSettings;
use App\Services\Fiscal\Nfe55DocumentSnapshot;
use App\Services\Fiscal\Nfe55NumberReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class Nfe55ProductionConfigurationTest extends TestCase
{
    use RefreshDatabase, WithConfiguredCompany;

    private function operator(): User
    {
        return User::query()->create(['name'=>'Operador','email'=>'production55@example.test',
            'password'=>'password','role'=>'admin','is_active'=>true]);
    }

    private function settings(array $extra=[]): array
    {
        return array_replace(['environment'=>'production','series'=>1,'next_number'=>23,
            'production_series'=>7,'production_next_number'=>104,'production_enabled'=>'1','enabled'=>'1'],$extra);
    }

    private function configure(): void
    {
        foreach($this->settings() as $key=>$value)AppSetting::put('nfe',$key,$value);
    }

    private function draft(string $environment='production',int $series=7): NfeDraft
    {
        $nature=OperationNature::query()->create(['name'=>'VENDA','operation_type'=>'outbound','is_active'=>true]);
        $draft=NfeDraft::query()->create(['status'=>'draft','environment'=>$environment,'series'=>$series,
            'issue_date'=>'2026-10-25','issue_time'=>'14:00','operation_type'=>'outbound','destination'=>'internal',
            'purpose'=>'normal','operation_nature_id'=>$nature->id,'validated_at'=>now()]);
        return $draft->fresh(['items','operationNature']);
    }

    private function reserve(NfeDraft $draft): NfeDraft
    {
        $hash=app(Nfe55DocumentSnapshot::class)->fingerprint($draft,CompanySetting::current());
        return app(Nfe55NumberReservationService::class)->reserve($draft->id,$hash);
    }

    public function test_settings_expose_and_persist_independent_production_numbering(): void
    {
        $this->actingAs($this->operator())->get(route('settings.index',['tab'=>'nfe']))
            ->assertOk()->assertSee('name="production_series"',false)->assertSee('name="production_next_number"',false);
        $this->post(route('settings.group.update','nfe'),$this->settings())->assertSessionHasNoErrors();
        self::assertSame(['series'=>7,'next_number'=>104],app(FiscalDocumentSettings::class)->nfeNumbering('production'));
        self::assertSame(['series'=>1,'next_number'=>23],app(FiscalDocumentSettings::class)->nfeNumbering('homologation'));
    }

    public function test_production_never_inherits_homologation_numbering(): void
    {
        $this->actingAs($this->operator())->post(route('settings.group.update','nfe'),$this->settings([
            'production_series'=>null,'production_next_number'=>null]))->assertSessionHasErrors(['production_series']);
        self::assertNull(AppSetting::value('nfe','production_enabled'));
        $draft=$this->draft();
        try {$this->reserve($draft);self::fail('Produção sem sequência.');}
        catch(ValidationException) {self::assertNull($draft->fresh()->document_number);}
        self::assertNull(AppSetting::value('nfe','production_next_number'));
    }

    public function test_reservation_advances_only_the_explicit_production_sequence(): void
    {
        $this->configure();$draft=$this->draft();
        self::assertSame(104,(int)$this->reserve($draft)->document_number);
        self::assertSame(105,(int)AppSetting::value('nfe','production_next_number'));
        self::assertSame(23,(int)AppSetting::value('nfe','next_number'));
        try {$this->reserve($draft->fresh());self::fail('Reserva repetida.');}
        catch(ValidationException) {self::assertSame(105,(int)AppSetting::value('nfe','production_next_number'));}
    }

    public function test_production_reservation_refuses_a_disabled_flag_or_another_series(): void
    {
        $this->configure();$draft=$this->draft();
        AppSetting::put('nfe','production_enabled',false);
        try {$this->reserve($draft);self::fail('Produção sem autorização.');}
        catch(ValidationException) {self::assertNull($draft->fresh()->document_number);}
        AppSetting::put('nfe','production_enabled',true);$draft->update(['series'=>1]);
        try {$this->reserve($draft->fresh(['items','operationNature']));self::fail('Série herdada.');}
        catch(ValidationException) {self::assertSame(104,(int)AppSetting::value('nfe','production_next_number'));}
    }

    public function test_settings_reject_rewinding_a_reserved_production_sequence_atomically(): void
    {
        $this->configure();$this->reserve($this->draft());
        $this->actingAs($this->operator())->post(route('settings.group.update','nfe'),$this->settings())
            ->assertSessionHasErrors('production_next_number');
        self::assertSame(105,(int)AppSetting::value('nfe','production_next_number'));
    }

    public function test_settings_reject_an_inutilization_range_but_keep_environment_sequences_independent(): void
    {
        Nfe55Inutilization::query()->create(['issuer_document'=>'12345678000199','environment'=>'production',
            'year'=>2026,'series'=>7,'first_number'=>100,'last_number'=>104,'reason'=>'Faixa de teste de sequência',
            'status'=>'pending','requested_by'=>$this->operator()->id]);
        $this->actingAs(User::query()->first())->post(route('settings.group.update','nfe'),$this->settings())
            ->assertSessionHasErrors('production_next_number');
        $this->post(route('settings.group.update','nfe'),$this->settings(['production_next_number'=>105]))
            ->assertSessionHasNoErrors();
        self::assertSame(23,(int)AppSetting::value('nfe','next_number'));
    }

    public function test_raw_invalid_production_numbering_is_rejected_without_coercion(): void
    {
        $this->configure();
        foreach(['0','1.5','-1','1000000000','abc'] as $invalid) {
            AppSetting::put('nfe','production_next_number',$invalid);
            try {app(FiscalDocumentSettings::class)->nfeNumbering('production');self::fail('Sequência inválida aceita.');}
            catch(ValidationException) {self::assertSame($invalid,AppSetting::value('nfe','production_next_number'));}
        }
    }

    public function test_opening_a_new_production_draft_uses_production_series(): void
    {
        $this->configure();
        $response=$this->actingAs($this->operator())->get(route('fiscal.nfe.create'))->assertOk();
        self::assertSame('production',$response->viewData('draft')->environment);
        self::assertSame(7,(int)$response->viewData('draft')->series);
        self::assertSame(104,$response->viewData('nextNumber'));
    }

    public function test_editing_an_existing_draft_preserves_its_environment_when_settings_change(): void
    {
        $this->configure();$draft=$this->draft('homologation',1);
        $this->actingAs($this->operator())->put(route('fiscal.nfe.update',$draft),[
            'draft_action'=>'draft','operation_type'=>'outbound','destination'=>'internal',
            'issue_date'=>'2026-10-25','issue_time'=>'14:00','items_json'=>'[]',
        ])->assertSessionHasNoErrors();
        self::assertSame('homologation',$draft->fresh()->environment);
        self::assertSame(1,(int)$draft->fresh()->series);
    }
}
