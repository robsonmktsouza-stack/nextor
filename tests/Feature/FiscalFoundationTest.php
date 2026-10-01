<?php

namespace Tests\Feature;

use App\Fiscal\Certificate\A1CertificateReader;
use App\Fiscal\DTO\AccessKeyData;
use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Exceptions\CertificateException;
use App\Fiscal\Exceptions\InvalidStateTransitionException;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Nfce\AccessKeyGenerator;
use App\Fiscal\Nfce\FiscalSequenceService;
use App\Fiscal\State\FiscalDocumentStateMachine;
use App\Fiscal\Support\Modulo11;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_modulo_11_matches_official_dfe_access_key_vector(): void
    {
        $base = '5206043300991100250655012000000780026730161';

        $this->assertSame(5, Modulo11::accessKeyDigit($base));
    }

    public function test_access_key_generator_builds_44_char_nfce_key(): void
    {
        $generator = new AccessKeyGenerator();

        $key = $generator->generate(new AccessKeyData(
            cUf: '35',
            issueDate: new DateTimeImmutable('2026-10-01 12:00:00-03:00'),
            emitterDocument: '12.345.678/0001-95',
            model: '65',
            series: 1,
            number: 123,
            emissionType: 1,
            numericCode: '87654321',
        ));

        $this->assertSame('35261012345678000195650010000001231876543210', $key);
        $this->assertSame(44, strlen($key));
    }

    public function test_access_key_rejects_invalid_state_code(): void
    {
        $this->expectException(\App\Fiscal\Exceptions\InvalidFiscalDataException::class);

        new AccessKeyData(
            cUf: '00',
            issueDate: new DateTimeImmutable('2026-10-01'),
            emitterDocument: '12345678000195',
            model: '65',
            series: 1,
            number: 1,
            emissionType: 1,
            numericCode: '12345678',
        );
    }

    public function test_access_key_accepts_alphanumeric_cnpj_shape_with_numeric_check_digits(): void
    {
        $data = new AccessKeyData(
            cUf: '35',
            issueDate: new DateTimeImmutable('2026-10-01'),
            emitterDocument: '12ABC678000195',
            model: '65',
            series: 1,
            number: 1,
            emissionType: 1,
            numericCode: '12345678',
        );

        $this->assertSame('12ABC678000195', $data->emitterDocument);
    }

    public function test_state_machine_does_not_allow_authorized_document_to_return_to_draft(): void
    {
        $machine = new FiscalDocumentStateMachine();

        $this->expectException(InvalidStateTransitionException::class);
        $machine->assertCanTransition(FiscalDocumentState::AUTHORIZED, FiscalDocumentState::DRAFT);
    }

    public function test_authorized_document_can_be_cancelled(): void
    {
        $machine = new FiscalDocumentStateMachine();

        $this->assertTrue(
            $machine->canTransition(FiscalDocumentState::AUTHORIZED, FiscalDocumentState::CANCELLED)
        );
    }

    public function test_sequence_reservation_is_monotonic(): void
    {
        $company = FiscalCompany::query()->create([
            'legal_name' => 'Empresa Homologacao Ltda',
            'cnpj' => '12345678000195',
            'state_registration' => '123456789',
            'crt' => '1',
            'uf' => 'SP',
            'city_ibge' => '3550308',
            'street' => 'Rua Teste',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'Sao Paulo',
            'zip_code' => '01001000',
            'environment' => FiscalEnvironment::HOMOLOGATION,
            'production_enabled' => false,
            'is_active' => true,
        ]);

        $service = app(FiscalSequenceService::class);
        $service->ensure($company, 1, FiscalEnvironment::HOMOLOGATION);

        $this->assertSame(1, $service->reserveNext($company, 1, FiscalEnvironment::HOMOLOGATION));
        $this->assertSame(2, $service->reserveNext($company, 1, FiscalEnvironment::HOMOLOGATION));
    }

    public function test_invalid_a1_payload_is_rejected_without_exposing_secret(): void
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('Não foi possível abrir o certificado A1');

        (new A1CertificateReader())->read('not-a-pfx', 'super-secret');
    }
}
