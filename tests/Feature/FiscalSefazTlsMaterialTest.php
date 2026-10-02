<?php

namespace Tests\Feature;

use App\Fiscal\Certificate\A1CertificateVault;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Sefaz\DTO\TlsCertificateFiles;
use App\Fiscal\Sefaz\Support\A1MutualTlsMaterialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestA1CertificateFactory;
use Tests\TestCase;

class FiscalSefazTlsMaterialTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutual_tls_pem_files_are_private_and_deleted_in_finally(): void
    {
        $company = FiscalCompany::query()->create([
            'legal_name' => 'Empresa TLS Teste Ltda',
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
        ]);

        $fixture = TestA1CertificateFactory::make();
        app(A1CertificateVault::class)->store(
            $company,
            $fixture['pfx'],
            $fixture['password'],
        );

        $certificatePath = null;
        $privateKeyPath = null;

        app(A1MutualTlsMaterialProvider::class)->withPemFiles(
            $company,
            function (TlsCertificateFiles $files) use (&$certificatePath, &$privateKeyPath): void {
                $certificatePath = $files->certificatePath;
                $privateKeyPath = $files->privateKeyPath;

                $this->assertFileExists($certificatePath);
                $this->assertFileExists($privateKeyPath);
                $this->assertStringContainsString('BEGIN CERTIFICATE', file_get_contents($certificatePath));
                $this->assertStringContainsString('PRIVATE KEY', file_get_contents($privateKeyPath));

                if (PHP_OS_FAMILY !== 'Windows') {
                    $this->assertSame(0600, fileperms($certificatePath) & 0777);
                    $this->assertSame(0600, fileperms($privateKeyPath) & 0777);
                }
            },
        );

        $this->assertNotNull($certificatePath);
        $this->assertNotNull($privateKeyPath);
        $this->assertFileDoesNotExist($certificatePath);
        $this->assertFileDoesNotExist($privateKeyPath);
    }

    public function test_mutual_tls_files_are_deleted_even_when_callback_fails(): void
    {
        $company = FiscalCompany::query()->create([
            'legal_name' => 'Empresa TLS Finally Ltda',
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
        ]);

        $fixture = TestA1CertificateFactory::make();
        app(A1CertificateVault::class)->store(
            $company,
            $fixture['pfx'],
            $fixture['password'],
        );

        $paths = [];

        try {
            app(A1MutualTlsMaterialProvider::class)->withPemFiles(
                $company,
                function (TlsCertificateFiles $files) use (&$paths): void {
                    $paths = [$files->certificatePath, $files->privateKeyPath];
                    throw new \RuntimeException('callback-test');
                },
            );
            $this->fail('Callback deveria falhar.');
        } catch (\RuntimeException $e) {
            $this->assertSame('callback-test', $e->getMessage());
        }

        $this->assertCount(2, $paths);
        $this->assertFileDoesNotExist($paths[0]);
        $this->assertFileDoesNotExist($paths[1]);
    }
}
