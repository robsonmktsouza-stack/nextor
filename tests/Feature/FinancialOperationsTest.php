<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
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
