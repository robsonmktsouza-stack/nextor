<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\PdvCashSession;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PdvOperationsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_and_last_sale_are_visible_only_in_operations_modal(): void
    {
        AppSetting::put('pdv','require_cash_opening',true);
        AppSetting::put('pdv','allow_cash_movements',true);

        $operator=User::query()->create([
            'name'=>'Operador PDV',
            'email'=>'pdv-operations@example.com',
            'password'=>'SenhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
        PdvCashSession::query()->create([
            'user_id'=>$operator->id,
            'opened_at'=>now()->subHour(),
            'opening_amount'=>'17.00',
        ]);
        $sale=Sale::query()->create([
            'user_id'=>$operator->id,
            'source'=>'pdv',
            'operation_type'=>'sale',
            'status'=>'completed',
            'subtotal'=>'12.90',
            'discount_total'=>'0',
            'total'=>'12.90',
            'completed_at'=>now(),
        ]);

        $response=$this->actingAs($operator)->get(route('pdv.index'))->assertOk()
            ->assertSee('id="pdvOperationsModal"',false)
            ->assertSee('pdv-operations-overview',false)
            ->assertSee('Aberto às')
            ->assertSee('R$ 17,00')
            ->assertSee('Última venda')
            ->assertSee('#'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT))
            ->assertSee('data-pdv-operation="close-cash"',false)
            ->assertSee('data-pdv-operation="supply"',false)
            ->assertSee('data-pdv-operation="withdrawal"',false)
            ->assertDontSee('class="pdv-cash-session-bar',false)
            ->assertDontSee('class="pdv-last-sale"',false);

        $html=$response->getContent();
        self::assertIsString($html);
        $operationsAt=strpos($html,'id="pdvOperationsModal"');
        $lastSaleAt=strpos($html,'Última venda');
        $cashAt=strpos($html,'R$ 17,00');
        self::assertNotFalse($operationsAt);
        self::assertGreaterThan($operationsAt,$lastSaleAt);
        self::assertGreaterThan($operationsAt,$cashAt);
    }

    public function test_operations_are_available_without_previously_completed_sales(): void
    {
        AppSetting::put('pdv','require_cash_opening',true);
        $operator=User::query()->create([
            'name'=>'Caixa Novo',
            'email'=>'pdv-empty@example.com',
            'password'=>'SenhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);

        $this->actingAs($operator)->get(route('pdv.index'))->assertOk()
            ->assertSee('Caixa fechado')
            ->assertSee('data-pdv-operation="open-cash"',false)
            ->assertSee('id="pdvCashOpenDialog"',false)
            ->assertDontSee('class="pdv-cash-session-bar',false)
            ->assertDontSee('class="pdv-last-sale"',false);
    }
}
