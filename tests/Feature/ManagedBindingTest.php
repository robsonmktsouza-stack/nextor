<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManagedBindingTest extends TestCase
{
    use RefreshDatabase;

    private array $folders = [];

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            foreach ([
                'storage/app/private/lumeron-control.json',
                'app/Console/Commands/CentralLicenseIdentity.php',
                'config/central_license.php', 'bootstrap/app.php',
                'artisan', '.env',
            ] as $item) {
                @unlink($folder.'/'.$item);
            }
            foreach ([
                'storage/app/private', 'storage/app', 'storage',
                'app/Console/Commands', 'app/Console', 'app', 'config', 'bootstrap',
            ] as $dir) {
                @rmdir($folder.'/'.$dir);
            }
            @rmdir($folder);
        }
        parent::tearDown();
    }

    private function company(): Company
    {
        return Company::create([
            'instance_id' => (string) Str::uuid(),
            'legal_name' => 'B&B BAR E MERCADINHO DO DIL LTDA',
            'cnpj' => '39323356000100',
            'email' => 'empresa@example.test',
            'subdomain' => 'beb-bar-do-dil',
            'plan' => 'standard',
            'license_status' => 'active',
            'modules' => ['pdv', 'fiscal'],
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    private function fakeLocalErp(string $cnpj): string
    {
        $dir = sys_get_temp_dir().'/lumeron-bind-'.bin2hex(random_bytes(7));
        $this->folders[] = $dir;
        foreach (['bootstrap', 'config', 'app/Console/Commands', 'storage/app/private'] as $subdir) {
            mkdir($dir.'/'.$subdir, 0700, true);
        }
        file_put_contents($dir.'/bootstrap/app.php', "<?php\n");
        file_put_contents($dir.'/config/central_license.php', "<?php\n");
        file_put_contents($dir.'/app/Console/Commands/CentralLicenseIdentity.php', "<?php\n");
        file_put_contents($dir.'/.env', "APP_ENV=testing\n");
        file_put_contents($dir.'/artisan', "<?php echo '". $cnpj ."'.PHP_EOL;\n");
        return $dir;
    }

    public function test_existing_company_is_bound_without_user_confirmation_or_secret_output(): void
    {
        $company = $this->company();
        $path = $this->fakeLocalErp($company->cnpj);
        config()->set('app.url', 'http://127.0.0.1:8000');

        $this->artisan('control:bind-local', [
            'erp-path' => $path,
            '--url' => 'http://127.0.0.1:8000',
        ])->assertExitCode(0);

        $binding = json_decode((string) file_get_contents($path.'/storage/app/private/lumeron-control.json'), true);
        $this->assertSame($company->instance_id, $binding['instance_id']);
        $this->assertSame($company->cnpj, $binding['cnpj']);
        $this->assertSame($company->license_secret, $binding['secret']);
        $this->assertSame('http://127.0.0.1:8000', $binding['url']);
        $this->assertSame('managed_binding', $company->events()->first()->event_type);
        $this->assertSame('active', $company->fresh()->currentStatus());
    }

    public function test_wrong_erp_cnpj_does_not_create_any_managed_connection(): void
    {
        $this->company();
        $path = $this->fakeLocalErp('11222333000181');
        $this->artisan('control:bind-local', [
            'erp-path' => $path,
            '--url' => 'http://127.0.0.1:8000',
        ])->assertExitCode(1);
        $this->assertFileDoesNotExist($path.'/storage/app/private/lumeron-control.json');
    }

    public function test_untrusted_local_http_endpoint_is_rejected(): void
    {
        $company = $this->company();
        $path = $this->fakeLocalErp($company->cnpj);
        $this->artisan('control:bind-local', [
            'erp-path' => $path,
            '--url' => 'http://example.org',
        ])->assertExitCode(1);
        $this->assertFileDoesNotExist($path.'/storage/app/private/lumeron-control.json');
    }
}
