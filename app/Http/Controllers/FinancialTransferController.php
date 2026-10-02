<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FinancialAccount;
use App\Models\FinancialTransfer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinancialTransferController extends Controller
{
    public function index(Request $request)
    {
        $month=(string)$request->query('month',now()->format('Y-m'));
        try{$period=Carbon::createFromFormat('Y-m',$month)->startOfMonth();}catch(\Throwable){$period=now()->startOfMonth();}
        $month=$period->format('Y-m');
        $term=trim((string)$request->query('search',''));

        $query=FinancialTransfer::query()
            ->with(['fromAccount','toAccount','user'])
            ->whereYear('transfer_date',$period->year)
            ->whereMonth('transfer_date',$period->month)
            ->when($term,fn($q)=>$q->where(function($search) use($term){
                $search->where('description','like',"%{$term}%")
                    ->orWhereHas('fromAccount',fn($account)=>$account->where('name','like',"%{$term}%"))
                    ->orWhereHas('toAccount',fn($account)=>$account->where('name','like',"%{$term}%"));
            }));

        $activeTotal=(float)(clone $query)->whereNull('cancelled_at')->sum('amount');
        $cancelledCount=(clone $query)->whereNotNull('cancelled_at')->count();
        $perPage=AppSetting::tablePerPage($request);
        $transfers=$query->orderByDesc('transfer_date')->orderByDesc('id')->paginate($perPage)->withQueryString();

        return view('finance.transfers.index',[
            'transfers'=>$transfers,'month'=>$month,'term'=>$term,'activeTotal'=>$activeTotal,'cancelledCount'=>$cancelledCount,
            'prevMonth'=>$period->copy()->subMonth()->format('Y-m'),
            'nextMonth'=>$period->copy()->addMonth()->format('Y-m'),
            'monthLabel'=>ucfirst($period->locale('pt_BR')->translatedFormat('F Y')),
        ]);
    }

    public function create()
    {
        return view('finance.transfers.form',[
            'accounts'=>FinancialAccount::query()->where('is_active',true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'from_account_id'=>['required','integer','exists:financial_accounts,id'],
            'to_account_id'=>['required','integer','exists:financial_accounts,id'],
            'transfer_date'=>['required','date'],
            'description'=>['nullable','string','max:190'],
            'notes'=>['nullable','string','max:2000'],
        ]);

        if((int)$data['from_account_id']===(int)$data['to_account_id']) {
            throw ValidationException::withMessages(['to_account_id'=>'A conta de destino deve ser diferente da origem.']);
        }

        $data['user_id']=$request->user()->id;
        FinancialTransfer::query()->create($data);

        return redirect()->route('finance.transfers.index')->with('success','Transferência registrada.');
    }

    public function cancel(FinancialTransfer $transfer)
    {
        if($transfer->cancelled_at) return back()->with('warning','Transferência já cancelada.');
        $transfer->update(['cancelled_at'=>now()]);
        return back()->with('success','Transferência cancelada e saldo revertido.');
    }
}
