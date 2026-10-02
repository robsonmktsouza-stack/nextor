<?php

namespace Tests\Integration\Fiscal;

use App\Fiscal\Contracts\FiscalEngineInterface;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Models\FiscalCompany;
use Tests\TestCase;

class SefazStatusServiceIntegrationTest extends TestCase
{
    public function test_real_homologation_status_service(): void
    {
        if (filter_var(env('FISCAL_LIVE_TESTS', false), FILTER_VALIDATE_BOOL) !== true) {
            $this->markTestSkipped('FISCAL_LIVE_TESTS não está habilitado.');
        }

        $companyId = (int) env('FISCAL_LIVE_COMPANY_ID', 0);
        $this->assertGreaterThan(0, $companyId, 'Defina FISCAL_LIVE_COMPANY_ID para o teste live.');

        $company = FiscalCompany::query()->findOrFail($companyId);
        $this->assertSame(
            FiscalEnvironment::HOMOLOGATION,
            $company->environment,
            'Teste live não pode usar empresa em produção.',
        );

        /** @var FiscalEngineInterface $engine */
        $engine = app(FiscalEngineInterface::class);
        $result = $engine->statusService($company);

        $this->assertTrue($result->transportSuccess);
        $this->assertGreaterThanOrEqual(200, $result->httpStatus);
        $this->assertLessThan(300, $result->httpStatus);
        $this->assertMatchesRegularExpression('/^\d{3}$/', $result->cStat);
        $this->assertNotSame('', trim($result->xMotivo));
        $this->assertNotSame('', trim($result->attemptUuid));
    }
}
