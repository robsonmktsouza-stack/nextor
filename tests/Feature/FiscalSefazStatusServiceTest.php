<?php

namespace Tests\Feature;

use App\Fiscal\Contracts\FiscalEngineInterface;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Models\FiscalTransmission;
use App\Fiscal\Sefaz\Contracts\SefazTransportInterface;
use App\Fiscal\Sefaz\DTO\SefazHttpResponse;
use App\Fiscal\Sefaz\Exceptions\SefazProductionDisabledException;
use App\Fiscal\Sefaz\Exceptions\SefazTlsException;
use App\Fiscal\Sefaz\Exceptions\SefazTransportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeSefazTransport;
use Tests\TestCase;

class FiscalSefazStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_service_parses_realistic_response_and_persists_attempt(): void
    {
        $company = $this->company();
        $soap = file_get_contents(base_path('tests/Fixtures/Fiscal/sefaz/status-service-107-soap12.xml'));
        $fake = new FakeSefazTransport(new SefazHttpResponse(200, $soap, 87));
        $this->app->instance(SefazTransportInterface::class, $fake);

        /** @var FiscalEngineInterface $engine */
        $engine = app(FiscalEngineInterface::class);
        $result = $engine->statusService($company);

        $this->assertTrue($result->transportSuccess);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame('107', $result->cStat);
        $this->assertSame('Servico em Operacao', $result->xMotivo);
        $this->assertSame('operational', $result->fiscalStatus);
        $this->assertSame('BA', $result->uf);
        $this->assertSame('SVRS', $result->authorizer);
        $this->assertSame(87, $result->responseTimeMs);

        $transmission = FiscalTransmission::query()->sole();
        $this->assertSame($result->attemptUuid, $transmission->attempt_uuid);
        $this->assertSame('success', $transmission->transport_status);
        $this->assertSame('107', $transmission->c_stat);
        $this->assertSame(200, $transmission->http_status);
        $this->assertNull($transmission->fiscal_document_id);
        $this->assertSame(hash('sha256', (string) $fake->lastBody), $transmission->request_sha256);
        $this->assertSame(hash('sha256', $soap), $transmission->response_sha256);

        $rawPayload = DB::table('fiscal_transmissions')
            ->where('id', $transmission->id)
            ->value('request_payload');

        $this->assertIsString($rawPayload);
        $this->assertStringNotContainsString('consStatServ', $rawPayload);
        $this->assertStringContainsString('consStatServ', (string) $transmission->request_payload);
    }

    public function test_http_200_is_not_collapsed_with_fiscal_status(): void
    {
        $company = $this->company();
        $soap = str_replace(
            ['<cStat>107</cStat>', '<xMotivo>Servico em Operacao</xMotivo>'],
            ['<cStat>108</cStat>', '<xMotivo>Servico Paralisado Momentaneamente</xMotivo>'],
            file_get_contents(base_path('tests/Fixtures/Fiscal/sefaz/status-service-107-soap12.xml')),
        );

        $this->app->instance(
            SefazTransportInterface::class,
            new FakeSefazTransport(new SefazHttpResponse(200, $soap, 31)),
        );

        $result = app(FiscalEngineInterface::class)->statusService($company);

        $this->assertTrue($result->transportSuccess);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame('108', $result->cStat);
        $this->assertSame('temporarily_unavailable', $result->fiscalStatus);
    }

    public function test_http_error_is_transport_error_and_is_persisted(): void
    {
        $company = $this->company();

        $this->app->instance(
            SefazTransportInterface::class,
            new FakeSefazTransport(new SefazHttpResponse(503, '<html>down</html>', 120)),
        );

        try {
            app(FiscalEngineInterface::class)->statusService($company);
            $this->fail('HTTP 503 deveria gerar exceção.');
        } catch (SefazTransportException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        $transmission = FiscalTransmission::query()->sole();
        $this->assertSame('http_error', $transmission->transport_status);
        $this->assertSame(503, $transmission->http_status);
        $this->assertNull($transmission->c_stat);
    }

    public function test_tls_error_is_distinct_and_persisted(): void
    {
        $company = $this->company();

        $this->app->instance(
            SefazTransportInterface::class,
            new FakeSefazTransport(
                error: new SefazTlsException('Não foi possível estabelecer conexão TLS com o autorizador.')
            ),
        );

        $this->expectException(SefazTlsException::class);

        try {
            app(FiscalEngineInterface::class)->statusService($company);
        } finally {
            $transmission = FiscalTransmission::query()->sole();
            $this->assertSame('tls_error', $transmission->transport_status);
            $this->assertNull($transmission->http_status);
        }
    }

    public function test_timeout_transport_error_is_persisted_without_retry(): void
    {
        $company = $this->company();
        $fake = new FakeSefazTransport(
            error: new SefazTransportException('Tempo limite excedido na comunicação com o autorizador.')
        );
        $this->app->instance(SefazTransportInterface::class, $fake);

        try {
            app(FiscalEngineInterface::class)->statusService($company);
            $this->fail('Timeout deveria gerar exceção.');
        } catch (SefazTransportException $e) {
            $this->assertStringContainsString('Tempo limite', $e->getMessage());
        }

        $this->assertSame(1, FiscalTransmission::query()->count());
        $this->assertSame('transport_error', FiscalTransmission::query()->sole()->transport_status);
    }

    public function test_production_is_blocked_before_endpoint_or_transport(): void
    {
        $company = $this->company([
            'environment' => FiscalEnvironment::PRODUCTION,
            'production_enabled' => true,
        ]);

        $fake = new FakeSefazTransport(new SefazHttpResponse(200, '', 1));
        $this->app->instance(SefazTransportInterface::class, $fake);

        $this->expectException(SefazProductionDisabledException::class);
        $this->expectExceptionMessage('produção ainda não está habilitada');

        try {
            app(FiscalEngineInterface::class)->statusService($company);
        } finally {
            $this->assertSame(0, FiscalTransmission::query()->count());
            $this->assertNull($fake->lastBody);
        }
    }

    public function test_artisan_diagnostic_command_outputs_safe_structured_status(): void
    {
        $company = $this->company();
        $soap = file_get_contents(base_path('tests/Fixtures/Fiscal/sefaz/status-service-107-soap12.xml'));

        $this->app->instance(
            SefazTransportInterface::class,
            new FakeSefazTransport(new SefazHttpResponse(200, $soap, 44)),
        );

        $this->artisan('fiscal:sefaz-status', ['company' => $company->id])
            ->expectsOutputToContain('NEXTOR FISCAL')
            ->expectsOutputToContain('Autorizador: SVRS')
            ->expectsOutputToContain('HTTP: 200')
            ->expectsOutputToContain('cStat: 107')
            ->assertSuccessful();
    }

    private function company(array $overrides = []): FiscalCompany
    {
        return FiscalCompany::query()->create(array_merge([
            'legal_name' => 'Empresa NFC-e Homologacao Ltda',
            'cnpj' => '12345678000195',
            'state_registration' => '123456789',
            'crt' => '1',
            'uf' => 'BA',
            'city_ibge' => '2926806',
            'street' => 'Rua Teste',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'Rio do Antonio',
            'zip_code' => '46220000',
            'environment' => FiscalEnvironment::HOMOLOGATION,
            'production_enabled' => false,
            'is_active' => true,
        ], $overrides));
    }
}
