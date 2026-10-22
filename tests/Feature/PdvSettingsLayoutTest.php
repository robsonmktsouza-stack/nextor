<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class PdvSettingsLayoutTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    public function test_pdv_settings_keep_switches_in_a_regular_grid_and_the_monetary_field_outside_it(): void
    {
        $admin=User::query()->create([
            'name'=>'Administrador','email'=>'settings-pdv@example.test',
            'password'=>'SenhaTeste123','role'=>'admin','is_active'=>true,
        ]);
        $response=$this->actingAs($admin)
            ->get(route('settings.index',['tab'=>'pdv']))
            ->assertOk()
            ->assertSee('pdv-settings.css')
            ->assertSee('Frente de caixa (PDV)')
            ->assertSee('Caixa e segurança')
            ->assertSee('Emissão fiscal')
            ->assertSee('name="supervisor_movement_min"',false);

        $html=$response->getContent();
        preg_match_all('/<div class="pdv-settings-options-grid">([\s\S]*?)<\/div>/',$html,$grids);
        self::assertCount(3,$grids[0]);
        foreach($grids[1] as $grid) {
            self::assertStringNotContainsString('col-6',$grid,
                'Campos com grid-column:span 6 não podem ficar dentro de uma grade de switches.');
            self::assertStringNotContainsString('settings-switch-grid',$grid);
        }
        foreach([
            'default_payment_method','receipt_width','receipt_copies','require_customer',
            'allow_discount','require_cash_opening','ask_consumer_document',
            'allow_split_payment','allow_cash_movements','supervisor_movement_min',
            'show_stock','auto_nfce',
        ] as $field) {
            self::assertSame(1,substr_count($html,'name="'.$field.'"'),
                'O campo '.$field.' deve existir uma única vez.');
        }
        $this->assertStringNotContainsString('grid-column:span 6',
            file_get_contents(public_path('css/pdv-settings.css')));
    }

    public function test_pdv_settings_save_supervisor_limit_and_other_existing_preferences(): void
    {
        $admin=User::query()->create([
            'name'=>'Administrador','email'=>'settings-pdv-save@example.test',
            'password'=>'SenhaTeste123','role'=>'admin','is_active'=>true,
        ]);
        $this->actingAs($admin)->post(route('settings.group.update','pdv'),[
            'default_payment_method'=>null,
            'receipt_width'=>'80',
            'receipt_copies'=>'2',
            'require_cash_opening'=>'1',
            'allow_cash_movements'=>'1',
            'supervisor_movement_min'=>'350.00',
            'allow_split_payment'=>'1',
            'show_stock'=>'1',
        ])->assertRedirect(route('settings.index',['tab'=>'pdv']));

        self::assertSame(350,(int)AppSetting::value('pdv','supervisor_movement_min'));
        self::assertTrue((bool)AppSetting::value('pdv','require_cash_opening'));
        self::assertTrue((bool)AppSetting::value('pdv','allow_cash_movements'));
        self::assertSame(2,(int)AppSetting::value('pdv','receipt_copies'));
    }
}
