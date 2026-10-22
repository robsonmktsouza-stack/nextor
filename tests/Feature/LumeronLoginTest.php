<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class LumeronLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('instance.subdomain','autonunes');
        config()->set('instance.id','autonunes');
        config()->set('instance.base_domain','lumeron.com.br');
        config()->set('instance.login_portal',false);
        config()->set('instance.login_portal_host','lumeron.com.br');
    }

    private function user(): User
    {
        return User::query()->create([
            'name'=>'Caixa da loja','email'=>'caixa@exemplo.com.br',
            'username'=>'caixa01','password'=>'SenhaTesteSegura123',
            'role'=>'admin','is_active'=>true,
        ]);
    }

    public function test_admin_can_register_separate_usernames_inside_the_installation(): void
    {
        $this->actingAs($this->user())
            ->post(route('settings.users.store'),[
                'name'=>'Operador de caixa',
                'username'=>'operador01',
                'email'=>'operador@exemplo.com.br',
                'password'=>'SenhaOperador123',
                'role'=>'admin',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users',[
            'username'=>'operador01','email'=>'operador@exemplo.com.br',
        ]);

        $this->post(route('settings.users.store'),[
            'name'=>'Operador duplicado',
            'username'=>'operador01',
            'email'=>'outro@exemplo.com.br',
            'password'=>'SenhaOperador123',
            'role'=>'admin',
        ])->assertSessionHasErrors('username');
    }

    public function test_login_page_shows_subdomain_username_and_password(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Subdomínio (link de acesso)')
            ->assertSee('Nome de usuário')
            ->assertSee('Senha de acesso')
            ->assertSee('value="autonunes"',false)
            ->assertSee('Esqueci minha senha');
    }

    public function test_login_authenticates_username_only_inside_matching_subdomain(): void
    {
        $user=$this->user();
        $this->post('/login',[
            'subdomain'=>'autonunes','username'=>'caixa01',
            'password'=>'SenhaTesteSegura123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_correct_password_with_wrong_subdomain_is_rejected(): void
    {
        $this->user();
        $this->post('/login',[
            'subdomain'=>'outraempresa','username'=>'caixa01',
            'password'=>'SenhaTesteSegura123',
        ])->assertSessionHasErrors('subdomain');
        $this->assertGuest();
    }

    public function test_previous_email_login_still_works_for_existing_users(): void
    {
        $user=User::query()->create([
            'name'=>'Admin antigo','email'=>'administrador@exemplo.com.br',
            'password'=>'SenhaTesteSegura123','role'=>'admin',
        ]);
        $this->post('/login',[
            'subdomain'=>'autonunes','username'=>'administrador@exemplo.com.br',
            'password'=>'SenhaTesteSegura123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user=$this->user();
        $user->update(['is_active'=>false]);
        $this->post('/login',[
            'subdomain'=>'autonunes','username'=>'caixa01',
            'password'=>'SenhaTesteSegura123',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_portal_mode_disallows_local_password_authentication(): void
    {
        config()->set('instance.login_portal',true);
        $this->get('/login')->assertOk()->assertSee('data-portal="1"',false);
        $this->post('/login',[
            'subdomain'=>'autonunes','username'=>'caixa01','password'=>'x',
        ])->assertForbidden();
    }

    public function test_bootstrap_requires_correct_portal_origin_and_tenant_host(): void
    {
        // A URL absoluta define o host e o esquema efetivos vistos por
        // Request::getSchemeAndHttpHost(), sem afrouxar a checagem de origem.
        $this->withHeaders(['Origin'=>'https://lumeron.com.br'])
            ->get('https://autonunes.lumeron.com.br/login/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['csrf_token'])
            ->assertHeader('Access-Control-Allow-Origin','https://lumeron.com.br');

        $this->withHeaders(['Origin'=>'https://evil.example'])
            ->get('/login/bootstrap')->assertForbidden();
    }

    public function test_numeric_company_identifier_is_accepted(): void
    {
        config()->set('instance.subdomain', '11222333000181');
        $user = $this->user();
        $this->post('/login', [
            'subdomain'=>'11222333000181',
            'username'=>'caixa01',
            'password'=>'SenhaTesteSegura123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('https://11222333000181.lumeron.com.br',
            \App\Support\LoginSubdomain::tenantUrl('11222333000181'));
    }

    public function test_reserved_and_unsafe_identifiers_are_rejected(): void
    {
        foreach (['control','www','login','api','a--b','-abc','abc-','x'] as $slug) {
            $this->assertFalse(\App\Support\LoginSubdomain::valid($slug));
            $this->assertNull(\App\Support\LoginSubdomain::tenantUrl($slug));
        }
    }

    public function test_portal_cors_preflight_accepts_only_the_root_domain(): void
    {
        $this->withHeaders(['Origin'=>'https://lumeron.com.br'])
            ->options('https://autonunes.lumeron.com.br/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin','https://lumeron.com.br');
        $this->withHeaders(['Origin'=>'https://evil.example'])
            ->options('https://autonunes.lumeron.com.br/login')
            ->assertForbidden();
    }

    public function test_password_reset_is_scoped_to_this_installation(): void
    {
        $user=$this->user();
        $token=Password::broker()->createToken($user);
        $this->get(route('password.reset',['token'=>$token,'email'=>$user->email]))
            ->assertOk()->assertSee('Definir nova senha');
        $this->post(route('password.update'),[
            'token'=>$token,'email'=>$user->email,
            'password'=>'MinhaSenhaNova456','password_confirmation'=>'MinhaSenhaNova456',
        ])->assertRedirect(route('login'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('MinhaSenhaNova456',$user->fresh()->password));
    }
}
