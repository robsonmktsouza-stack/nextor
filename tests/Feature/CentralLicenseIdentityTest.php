<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CentralLicenseIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_company_cnpj_is_printed_as_machine_readable_identity(): void
    {
        config()->set('instance.cnpj', '');
        CompanySetting::current()->update(['document' => '39.323.356/0001-00']);
        $this->artisan('lumeron:license-identity')
            ->expectsOutput('39323356000100')
            ->assertExitCode(0);
    }

    public function test_empty_company_cnpj_is_rejected_without_creating_a_license_binding(): void
    {
        config()->set('instance.cnpj', '');
        CompanySetting::current()->update(['document' => null]);
        $this->artisan('lumeron:license-identity')
            ->expectsOutputToContain('O CNPJ da própria empresa está vazio')
            ->assertExitCode(1);
        $this->assertDatabaseCount('central_license_states', 0);
    }

    public function test_identity_cannot_pair_a_different_instance_cnpj(): void
    {
        config()->set('instance.cnpj', '11222333000181');
        CompanySetting::current()->update(['document' => '39.323.356/0001-00']);
        $this->artisan('lumeron:license-identity')
            ->expectsOutputToContain('é diferente do CNPJ fixado para esta instalação')
            ->assertExitCode(1);
    }
}
