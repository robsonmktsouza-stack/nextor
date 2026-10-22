<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Subdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CompanySubdomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::query()->create([
            'name'=>'Admin', 'email'=>'owner@example.test', 'password'=>'long-password',
        ]));
    }

    private function register(string $subdomain): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('clients.store'), [
            'legal_name'=>'EMPRESA DEMONSTRACAO LTDA',
            'cnpj'=>'11.222.333/0001-81',
            'email'=>'operador@example.test',
            'subdomain'=>$subdomain,
            'plan'=>'standard',
        ]);
    }

    public function test_numeric_cnpj_and_uppercase_are_canonical(): void
    {
        $this->register('11.222.333/0001-81')->assertRedirect();
        $this->assertSame('11222333000181', Company::query()->sole()->subdomain);
        $this->assertTrue(Subdomain::valid('11222333000181'));
        $this->assertSame('loja01', Subdomain::normalize(' Loja01 '));
    }

    public function test_reserved_or_unsafe_identifiers_are_rejected(): void
    {
        foreach (['control','LOGIN','www','api','a--b','-abc','abc-','foo.bar','a'] as $invalid) {
            $this->register($invalid)->assertSessionHasErrors('subdomain');
        }
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_second_company_cannot_reuse_identifier_even_with_different_case(): void
    {
        $this->register('Loja123')->assertRedirect();
        $this->register('LOJA123')->assertSessionHasErrors('subdomain');
        $this->assertDatabaseCount('companies', 1);
        $this->assertSame('loja123', Company::query()->sole()->subdomain);
    }

    public function test_identifier_cannot_be_changed_after_registration(): void
    {
        $this->register('empresa-original')->assertRedirect();
        $company = Company::query()->sole();
        $company->subdomain = 'empresa-outra';
        try {
            $company->save();
            $this->fail('O identificador foi alterado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subdomain', $exception->errors());
        }
        $this->assertSame('empresa-original', $company->fresh()->subdomain);
    }
}
