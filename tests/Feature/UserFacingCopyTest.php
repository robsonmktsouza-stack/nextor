<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserFacingCopyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Administrador',
            'email' => 'admin-textos@example.test',
            'password' => 'senha-segura-de-teste',
        ]);
    }

    public function test_admin_pages_do_not_display_implementation_notes(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            '/dashboard', '/automations', '/plans', '/plans/create', '/clients/create',
        ] as $url) {
            $page = $this->get($url)->assertOk();
            foreach ([
                'agendador Laravel', 'gerenciador de implantação',
                'instalação será vinculada automaticamente',
                'não gera faturas nem pagamentos',
                'Identificador técnico estável',
                'O cadastro reserva a identidade',
                'somente avisos internos serão registrados',
                'Os valores são referências administrativas',
                'Nenhuma regra envia cobranças',
            ] as $phrase) {
                $page->assertDontSee($phrase);
            }
        }

        $this->get('/automations')
            ->assertSee('Avisar antes do vencimento da licença.')
            ->assertSee('Avisar sobre licenças vencidas.')
            ->assertSee('Avisar sobre empresas sem comunicação recente.');
    }

    public function test_legacy_automation_descriptions_are_simplified_without_enabling_rules(): void
    {
        $descriptions = AutomationRule::query()->pluck('description', 'code');
        $this->assertSame('Avisar antes do vencimento da licença.', $descriptions['expiry_soon']);
        $this->assertSame('Avisar sobre licenças vencidas.', $descriptions['expired']);
        $this->assertSame('Avisar sobre empresas sem comunicação recente.', $descriptions['offline']);
        $this->assertSame(0, AutomationRule::query()->where('enabled', true)->count());
    }

    public function test_rule_update_and_manual_check_have_short_confirmations(): void
    {
        $this->actingAs($this->admin());
        $rule = AutomationRule::query()->where('code', 'expiry_soon')->firstOrFail();

        $this->put(route('automations.update', $rule), [
            'enabled' => '1',
            'days' => 7,
        ])->assertRedirect()->assertSessionHas('success', 'Automação atualizada.');

        $this->post(route('automations.run'))->assertRedirect()
            ->assertSessionHas('success', 'Verificação concluída. Novos avisos: 0.');
    }
}
