<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\FinancialRecurrence;
use App\Models\FinancialTransfer;
use App\Models\User;
use App\Services\BankStatementParser;
use App\Services\FinancialBalanceService;
use App\Services\FinancialRecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FinancialOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name'=>'Financeiro','email'=>'finance-ops@example.com','password'=>'senha123456']);
    }

    public function test_transfer_changes_account_balances_without_income_or_expense(): void
    {
        $user=$this->user();
        $from=FinancialAccount::query()->firstOrFail();
        $from->update(['opening_balance'=>'1000.00']);
        $to=FinancialAccount::query()->create(['name'=>'Banco','type'=>'bank','opening_balance'=>'100.00','is_active'=>true]);

        FinancialTransfer::query()->create([
            'from_account_id'=>$from->id,'to_account_id'=>$to->id,'user_id'=>$user->id,
            'amount'=>'250.00','transfer_date'=>'2026-10-02',
        ]);

        $balances=app(FinancialBalanceService::class);
        $this->assertSame(750.0,$balances->balance($from));
        $this->assertSame(350.0,$balances->balance($to));
    }

    public function test_recurrence_generates_due_entry_once(): void
    {
        $user=$this->user();
        $category=FinancialCategory::query()->where('type','expense')->firstOrFail();

        $recurrence=FinancialRecurrence::query()->create([
            'type'=>'payable','category_id'=>$category->id,'created_by'=>$user->id,'description'=>'Aluguel',
            'amount'=>'500.00','frequency'=>'monthly','interval_count'=>1,'start_date'=>'2026-10-01',
            'next_date'=>'2026-10-01','is_active'=>true,
        ]);

        $service=app(FinancialRecurrenceService::class);
        $this->assertSame(1,$service->generateDue($recurrence,\Carbon\Carbon::parse('2026-10-02')));
        $this->assertSame(0,$service->generateDue($recurrence->fresh(),\Carbon\Carbon::parse('2026-10-02')));
        $this->assertDatabaseHas('financial_entries',['description'=>'Aluguel','amount'=>'500.00','recurrence_id'=>$recurrence->id]);
    }

    public function test_bulk_settlement_closes_selected_open_entries(): void
    {
        $user=$this->user();
        $account=FinancialAccount::query()->firstOrFail();
        $category=FinancialCategory::query()->where('type','income')->firstOrFail();

        $entry=FinancialEntry::query()->create([
            'type'=>'receivable','status'=>'open','category_id'=>$category->id,'created_by'=>$user->id,
            'description'=>'Mensalidade teste','issue_date'=>'2026-10-02','competence_date'=>'2026-10-02',
            'due_date'=>'2026-10-10','amount'=>'180.00','paid_amount'=>'0.00',
        ]);

        $this->actingAs($user)->post(route('finance.entries.bulk-action'),[
            'ids'=>[$entry->id],
            'action'=>'settle',
            'financial_account_id'=>$account->id,
            'settled_at'=>'2026-10-02',
            'payment_method'=>'pix',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('financial_entries',[
            'id'=>$entry->id,'status'=>'paid','paid_amount'=>'180.00',
        ]);
        $this->assertDatabaseHas('financial_settlements',[
            'financial_entry_id'=>$entry->id,'financial_account_id'=>$account->id,'amount'=>'180.00',
        ]);
    }

    public function test_bulk_cancel_and_reopen_manual_entries(): void
    {
        $user=$this->user();
        $category=FinancialCategory::query()->where('type','expense')->firstOrFail();

        $entry=FinancialEntry::query()->create([
            'type'=>'payable','status'=>'open','category_id'=>$category->id,'created_by'=>$user->id,
            'description'=>'Despesa teste','issue_date'=>'2026-10-02','competence_date'=>'2026-10-02',
            'due_date'=>'2026-10-10','amount'=>'75.00','paid_amount'=>'0.00',
        ]);

        $this->actingAs($user)->post(route('finance.entries.bulk-action'),[
            'ids'=>[$entry->id],'action'=>'cancel',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('financial_entries',['id'=>$entry->id,'status'=>'cancelled']);

        $this->actingAs($user)->post(route('finance.entries.bulk-action'),[
            'ids'=>[$entry->id],'action'=>'reopen',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('financial_entries',['id'=>$entry->id,'status'=>'open']);
        $this->assertNull($entry->fresh()->cancelled_at);
    }

    public function test_bulk_primary_edit_updates_only_informed_fields(): void
    {
        $user=$this->user();
        $category=FinancialCategory::query()->where('type','income')->firstOrFail();

        $entry=FinancialEntry::query()->create([
            'type'=>'receivable','status'=>'open','category_id'=>$category->id,'created_by'=>$user->id,
            'description'=>'Descrição original','keywords'=>'manter','issue_date'=>'2026-10-02','competence_date'=>'2026-10-02',
            'due_date'=>'2026-10-10','amount'=>'100.00','paid_amount'=>'0.00',
        ]);

        $this->actingAs($user)->post(route('finance.entries.bulk-action'),[
            'ids'=>[$entry->id],
            'action'=>'edit_primary',
            'description'=>'Descrição alterada',
            'due_date'=>'2026-10-20',
        ])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame('Descrição alterada',$entry->description);
        $this->assertSame('2026-10-20',$entry->due_date->toDateString());
        $this->assertSame('100.00',$entry->amount);
        $this->assertSame('manter',$entry->keywords);
    }

    public function test_quick_bulk_settlement_uses_entry_account_and_payment_method(): void
    {
        $user=$this->user();
        $account=FinancialAccount::query()->firstOrFail();
        $category=FinancialCategory::query()->where('type','income')->firstOrFail();

        $entry=FinancialEntry::query()->create([
            'type'=>'receivable','status'=>'open','category_id'=>$category->id,'financial_account_id'=>$account->id,'created_by'=>$user->id,
            'description'=>'Recebimento rápido','issue_date'=>'2026-10-02','competence_date'=>'2026-10-02',
            'due_date'=>'2026-10-10','amount'=>'90.00','paid_amount'=>'0.00','payment_method'=>'pix',
        ]);

        $this->actingAs($user)->post(route('finance.entries.bulk-action'),[
            'ids'=>[$entry->id],
            'action'=>'settle_quick',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('financial_entries',['id'=>$entry->id,'status'=>'paid','paid_amount'=>'90.00']);
        $this->assertDatabaseHas('financial_settlements',[
            'financial_entry_id'=>$entry->id,'financial_account_id'=>$account->id,'payment_method'=>'pix','amount'=>'90.00',
        ]);
    }

    public function test_ofx_parser_extracts_bank_transactions(): void
    {
        $ofx="OFXHEADER:100\nDATA:OFXSGML\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>
<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261002120000<TRNAMT>150.50<FITID>ABC1<NAME>CLIENTE TESTE<MEMO>PIX RECEBIDO</STMTTRN>
<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261003120000<TRNAMT>-45.20<FITID>ABC2<NAME>FORNECEDOR</STMTTRN>
</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
        $file=UploadedFile::fake()->createWithContent('extrato.ofx',$ofx);
        $result=app(BankStatementParser::class)->parse($file);

        $this->assertCount(2,$result['rows']);
        $this->assertSame(150.5,$result['rows'][0]['amount']);
        $this->assertSame('debit',$result['rows'][1]['transaction_type']);
    }

    public function test_new_finance_sections_require_authentication(): void
    {
        $this->get('/finance/transfers')->assertRedirect('/login');
        $this->get('/finance/recurrences')->assertRedirect('/login');
        $this->get('/finance/receipts')->assertRedirect('/login');
        $this->get('/finance/reconciliation')->assertRedirect('/login');
    }
}
