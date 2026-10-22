<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class PdvCashOpeningLayoutTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    public function test_cash_opening_identifies_account_without_editable_or_focusable_operator_input(): void
    {
        $user=User::query()->create([
            'name'=>'Maria Operadora',
            'email'=>'maria-cash-layout@example.test',
            'password'=>'SenhaTeste123',
            'role'=>'operator',
            'permissions'=>['pdv'],
            'is_active'=>true,
        ]);
        $response=$this->actingAs($user)->get(route('pdv.index'))->assertOk()
            ->assertSee('id="pdvCashOpenDialog"',false)
            ->assertSee('class="pdv-cash-open-identity"',false)
            ->assertSee('Maria Operadora')
            ->assertSee('name="register_id"',false)
            ->assertSee('name="opening_amount"',false)
            ->assertSee('name="credential"',false)
            ->assertSee('class="pdv-cash-open-actions"',false);
        $html=$response->getContent();
        preg_match('/<dialog[^>]*id="pdvCashOpenDialog"[\s\S]*?<\/dialog>/',$html,$parts);
        self::assertNotEmpty($parts,'O formulário de abertura precisa estar no modal.');
        self::assertStringNotContainsString('readonly',$parts[0],
            'O operador é exibido como identidade fixa, sem um input somente leitura.');
        self::assertStringNotContainsString('name="user_id"',$parts[0],
            'O proprietário do turno vem obrigatoriamente do login, nunca do formulário.');
        self::assertSame(1,substr_count($parts[0],'name="credential"'));
    }

    public function test_cash_open_dialog_has_its_own_responsive_styles_and_amount_focus(): void
    {
        $css=file_get_contents(public_path('css/pdv-lumeron.css'));
        $js=file_get_contents(public_path('js/pdv.js'));
        self::assertStringContainsString('#pdvCashOpenDialog.pdv-cash-open-modal',$css);
        self::assertStringContainsString('#pdvCashOpenDialog .pdv-cash-open-identity strong',$css);
        self::assertStringContainsString('#pdvCashOpenDialog .pdv-cash-open-actions',$css);
        self::assertStringContainsString('@media(max-width:560px)',$css);
        self::assertStringContainsString('cashOpenDialog.showModal()',$js);
        self::assertStringContainsString('input[name="opening_amount"]',$js);
        self::assertStringContainsString("?.focus({preventScroll:true})",$js);
    }
}
