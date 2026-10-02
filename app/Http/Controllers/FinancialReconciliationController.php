<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\FinancialBankImport;
use App\Models\FinancialBankTransaction;
use App\Models\FinancialEntry;
use App\Services\BankStatementParser;
use App\Services\FinancialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $importId=$request->integer('import');
        $imports=FinancialBankImport::query()->with('account')->latest('id')->paginate(15)->withQueryString();
        $selected=$importId
            ? FinancialBankImport::query()->with('account')->find($importId)
            : FinancialBankImport::query()->with('account')->latest('id')->first();

        $transactions=collect();
        $candidateMap=[];
        $summary=['count'=>0,'pending'=>0,'reconciled'=>0,'credits'=>0.0,'debits'=>0.0];

        if($selected) {
            $transactions=$selected->transactions()->with(['entry','settlement'])->orderBy('transaction_date')->orderBy('sequence')->get();

            $summary=[
                'count'=>$transactions->count(),
                'pending'=>$transactions->whereNull('reconciled_at')->count(),
                'reconciled'=>$transactions->whereNotNull('reconciled_at')->count(),
                'credits'=>(float)$transactions->filter(fn($tx)=>(float)$tx->amount>0)->sum(fn($tx)=>(float)$tx->amount),
                'debits'=>(float)$transactions->filter(fn($tx)=>(float)$tx->amount<0)->sum(fn($tx)=>abs((float)$tx->amount)),
            ];

            foreach($transactions->whereNull('reconciled_at') as $transaction) {
                $type=(float)$transaction->amount>=0?'receivable':'payable';
                $amount=abs((float)$transaction->amount);
                $candidateMap[$transaction->id]=FinancialEntry::query()
                    ->with('customer')
                    ->where('type',$type)
                    ->whereIn('status',['open','partial'])
                    ->whereRaw('ABS((amount-paid_amount)-?) < 0.01',[$amount])
                    ->whereBetween('due_date',[
                        $transaction->transaction_date->copy()->subDays(15)->toDateString(),
                        $transaction->transaction_date->copy()->addDays(15)->toDateString(),
                    ])
                    ->orderByRaw('ABS(DATEDIFF(due_date,?))',[$transaction->transaction_date->toDateString()])
                    ->limit(5)->get();
            }
        }

        return view('finance.reconciliation.index',compact('imports','selected','transactions','candidateMap','summary'));
    }

    public function createImport()
    {
        return view('finance.reconciliation.import',[
            'accounts'=>FinancialAccount::query()->where('is_active',true)->orderBy('name')->get(),
        ]);
    }

    public function storeImport(Request $request, BankStatementParser $parser)
    {
        $data=$request->validate([
            'financial_account_id'=>['required','integer','exists:financial_accounts,id'],
            'file'=>['required','file','max:10240'],
        ]);

        $file=$request->file('file');
        $hash=hash_file('sha256',$file->getRealPath());

        if(FinancialBankImport::query()->where('financial_account_id',$data['financial_account_id'])->where('file_sha256',$hash)->exists()) {
            throw ValidationException::withMessages(['file'=>'Este arquivo já foi importado para essa conta.']);
        }

        $parsed=$parser->parse($file);

        $import=DB::transaction(function () use ($request,$data,$file,$hash,$parsed) {
            $import=FinancialBankImport::query()->create([
                'financial_account_id'=>$data['financial_account_id'],
                'created_by'=>$request->user()->id,
                'original_name'=>$file->getClientOriginalName(),
                'format'=>strtolower($file->getClientOriginalExtension()),
                'file_sha256'=>$hash,
                'period_start'=>$parsed['period_start'],
                'period_end'=>$parsed['period_end'],
                'transactions_count'=>count($parsed['rows']),
                'status'=>'imported',
            ]);

            foreach($parsed['rows'] as $row) $import->transactions()->create($row);
            return $import;
        });

        return redirect()->route('finance.reconciliation.index',['import'=>$import->id])->with('success','Extrato importado com '.count($parsed['rows']).' movimentação(ões).');
    }

    public function match(Request $request, FinancialBankTransaction $transaction, FinancialService $financial)
    {
        $data=$request->validate(['entry_id'=>['required','integer','exists:financial_entries,id']]);

        if($transaction->reconciled_at) throw ValidationException::withMessages(['entry_id'=>'Movimentação já conciliada.']);

        $entry=FinancialEntry::query()->findOrFail($data['entry_id']);
        $expected=(float)$transaction->amount>=0?'receivable':'payable';
        if($entry->type!==$expected) throw ValidationException::withMessages(['entry_id'=>'Tipo do lançamento não corresponde à movimentação bancária.']);

        $amount=abs((float)$transaction->amount);
        if(abs($entry->balance-$amount)>0.009) throw ValidationException::withMessages(['entry_id'=>'O saldo do lançamento deve ser igual ao valor da movimentação para conciliação automática.']);

        $settlement=$financial->settle($entry,[
            'amount'=>number_format($amount,2,'.',''),
            'settled_at'=>$transaction->transaction_date->toDateString(),
            'financial_account_id'=>$transaction->bankImport()->value('financial_account_id'),
            'payment_method'=>'bank_transfer',
            'notes'=>'Baixa criada pela conciliação bancária.',
        ],(int)$request->user()->id);

        $transaction->update([
            'financial_entry_id'=>$entry->id,
            'financial_settlement_id'=>$settlement->id,
            'reconciled_at'=>now(),
        ]);

        return back()->with('success','Movimentação conciliada.');
    }

    public function unmatch(FinancialBankTransaction $transaction, FinancialService $financial)
    {
        if(!$transaction->reconciled_at || !$transaction->financial_settlement_id) return back()->with('warning','Movimentação não está conciliada.');

        $settlement=$transaction->settlement()->first();
        if($settlement && !$settlement->reversed_at) $financial->reverse($settlement,'Estorno da conciliação bancária.');

        $transaction->update(['financial_entry_id'=>null,'financial_settlement_id'=>null,'reconciled_at'=>null]);
        return back()->with('success','Conciliação desfeita.');
    }
}
