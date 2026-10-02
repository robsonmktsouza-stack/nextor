<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\FinancialSettlement;
use App\Services\FinancialService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    private const PAYMENT_METHODS=[
        'cash'=>'Dinheiro',
        'pix'=>'PIX',
        'debit_card'=>'Cartão de débito',
        'credit_card'=>'Cartão de crédito',
        'bank_slip'=>'Boleto',
        'bank_transfer'=>'Transferência',
        'other'=>'Outro',
    ];

    public function dashboard(Request $request)
    {
        [$period,$month,$prevMonth,$nextMonth,$monthLabel]=$this->period($request);
        $start=$period->copy()->startOfMonth()->toDateString();
        $end=$period->copy()->endOfMonth()->toDateString();

        $settled=FinancialSettlement::query()
            ->whereNull('reversed_at')
            ->whereBetween('settled_at',[$start,$end]);

        $received=(clone $settled)
            ->whereHas('entry',fn($q)=>$q->where('type','receivable'))
            ->sum('amount');

        $paid=(clone $settled)
            ->whereHas('entry',fn($q)=>$q->where('type','payable'))
            ->sum('amount');

        $receivable=$this->openBalanceQuery('receivable')
            ->whereBetween('due_date',[$start,$end])
            ->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')
            ->value('total');

        $payable=$this->openBalanceQuery('payable')
            ->whereBetween('due_date',[$start,$end])
            ->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')
            ->value('total');

        $overdueReceivable=$this->openBalanceQuery('receivable')
            ->whereDate('due_date','<',today())
            ->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')
            ->value('total');

        $overduePayable=$this->openBalanceQuery('payable')
            ->whereDate('due_date','<',today())
            ->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')
            ->value('total');

        $cashFlow=FinancialSettlement::query()
            ->join('financial_entries','financial_entries.id','=','financial_settlements.financial_entry_id')
            ->whereNull('financial_settlements.reversed_at')
            ->whereBetween('financial_settlements.settled_at',[$start,$end])
            ->groupBy('financial_settlements.settled_at')
            ->orderBy('financial_settlements.settled_at')
            ->selectRaw(
                "financial_settlements.settled_at as day,
                SUM(CASE WHEN financial_entries.type='receivable' THEN financial_settlements.amount ELSE 0 END) as income,
                SUM(CASE WHEN financial_entries.type='payable' THEN financial_settlements.amount ELSE 0 END) as expense"
            )
            ->get();

        $upcoming=FinancialEntry::query()
            ->with(['category','customer'])
            ->whereIn('status',['open','partial'])
            ->whereBetween('due_date',[today()->toDateString(),today()->addDays(7)->toDateString()])
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        $recentEntries=FinancialEntry::query()
            ->with(['category','customer'])
            ->latest('id')
            ->limit(8)
            ->get();

        $accounts=$this->accountsWithBalance();

        return view('finance.dashboard',compact(
            'month','prevMonth','nextMonth','monthLabel',
            'received','paid','receivable','payable','overdueReceivable','overduePayable',
            'cashFlow','upcoming','recentEntries','accounts'
        ));
    }

    public function entries(Request $request)
    {
        [$period,$month,$prevMonth,$nextMonth,$monthLabel]=$this->period($request);
        $type=in_array($request->query('type'),['receivable','payable'],true)
            ? (string)$request->query('type')
            : '';
        $status=(string)$request->query('status','');
        $term=trim((string)$request->query('search',''));
        $categoryId=$request->integer('category_id');

        $requestedPerPage=$request->integer('per_page');
        if(in_array($requestedPerPage,[10,25,50,100],true)) {
            $request->session()->put('table_per_page',$requestedPerPage);
        }
        $perPage=(int)$request->session()->get('table_per_page',25);
        if(!in_array($perPage,[10,25,50,100],true)) $perPage=25;

        $query=FinancialEntry::query()
            ->with(['category','customer','sale'])
            ->whereYear('due_date',$period->year)
            ->whereMonth('due_date',$period->month)
            ->when($type,fn($q)=>$q->where('type',$type))
            ->when($categoryId,fn($q)=>$q->where('category_id',$categoryId))
            ->when($term,fn($q)=>$q->where(function($search) use ($term) {
                $search->where('description','like',"%{$term}%")
                    ->orWhere('document_number','like',"%{$term}%")
                    ->orWhereHas('customer',fn($party)=>$party
                        ->where('name','like',"%{$term}%")
                        ->orWhere('document','like',"%{$term}%"));
            }));

        if($status==='overdue') {
            $query->whereIn('status',['open','partial'])->whereDate('due_date','<',today());
        } elseif(in_array($status,['open','partial','paid','cancelled'],true)) {
            $query->where('status',$status);
        }

        $totalAmount=(clone $query)
            ->where('status','!=','cancelled')
            ->sum('amount');

        $openAmount=(clone $query)
            ->whereIn('status',['open','partial'])
            ->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')
            ->value('total');

        $entries=$query->orderBy('due_date')->orderBy('id')->paginate($perPage)->withQueryString();

        $categories=FinancialCategory::query()
            ->where('is_active',true)
            ->when($type==='receivable',fn($q)=>$q->where('type','income'))
            ->when($type==='payable',fn($q)=>$q->where('type','expense'))
            ->orderBy('name')
            ->get();

        return view('finance.index',compact(
            'entries','type','status','term','categoryId','categories',
            'month','prevMonth','nextMonth','monthLabel','totalAmount','openAmount'
        ));
    }

    public function create(Request $request)
    {
        $type=in_array($request->query('type'),['receivable','payable'],true)
            ? (string)$request->query('type')
            : 'receivable';

        $entry=new FinancialEntry([
            'type'=>$type,
            'status'=>'open',
            'issue_date'=>today(),
            'due_date'=>today(),
            'amount'=>'0.00',
            'paid_amount'=>'0.00',
        ]);

        return view('finance.form',$this->formData($entry,false));
    }

    public function store(Request $request)
    {
        $data=$this->validatedEntry($request);
        $data['status']='open';
        $data['paid_amount']='0.00';
        $data['created_by']=$request->user()->id;

        $entry=FinancialEntry::query()->create($data);

        return redirect()->route('finance.entries.show',$entry)->with('success','Lançamento financeiro criado.');
    }

    public function show(FinancialEntry $entry)
    {
        $entry->load(['category','customer','sale','salePayment','creator','settlements.account','settlements.user']);

        return view('finance.show',[
            'entry'=>$entry,
            'accounts'=>FinancialAccount::query()->where('is_active',true)->orderBy('name')->get(),
            'paymentMethods'=>self::PAYMENT_METHODS,
        ]);
    }

    public function edit(FinancialEntry $entry)
    {
        if($entry->sale_id!==null) {
            return redirect()->route('finance.entries.show',$entry)
                ->with('warning','Lançamentos gerados por venda não são editados diretamente.');
        }

        if($entry->status==='cancelled') {
            return redirect()->route('finance.entries.show',$entry)
                ->with('warning','Lançamento cancelado não pode ser editado.');
        }

        return view('finance.form',$this->formData($entry,true));
    }

    public function update(Request $request, FinancialEntry $entry)
    {
        if($entry->sale_id!==null) {
            throw ValidationException::withMessages(['entry'=>'Lançamentos originados de venda não podem ser editados diretamente.']);
        }

        if($entry->status==='cancelled') {
            throw ValidationException::withMessages(['entry'=>'Lançamento cancelado não pode ser editado.']);
        }

        $data=$this->validatedEntry($request);

        if((float)$data['amount']+0.0001<(float)$entry->paid_amount) {
            throw ValidationException::withMessages([
                'amount'=>'O valor do lançamento não pode ser menor que o total já baixado.'
            ]);
        }

        $entry->fill($data);
        $entry->status=(float)$entry->paid_amount<=0
            ? 'open'
            : ((float)$entry->paid_amount+0.0001>=(float)$entry->amount ? 'paid' : 'partial');
        $entry->save();

        return redirect()->route('finance.entries.show',$entry)->with('success','Lançamento atualizado.');
    }

    public function settle(Request $request, FinancialEntry $entry, FinancialService $financial)
    {
        $data=$request->validate([
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'settled_at'=>['required','date'],
            'financial_account_id'=>['required','integer','exists:financial_accounts,id'],
            'payment_method'=>['required',Rule::in(array_keys(self::PAYMENT_METHODS))],
            'notes'=>['nullable','string','max:1000'],
        ]);

        $financial->settle($entry,$data,(int)$request->user()->id);

        return redirect()->route('finance.entries.show',$entry)->with('success','Baixa registrada.');
    }

    public function reverseSettlement(Request $request, FinancialSettlement $settlement, FinancialService $financial)
    {
        $data=$request->validate([
            'reason'=>['nullable','string','max:500'],
        ]);

        $entryId=$settlement->financial_entry_id;
        $financial->reverse($settlement,$data['reason'] ?? null);

        return redirect()->route('finance.entries.show',$entryId)->with('success','Baixa estornada.');
    }

    public function cancel(FinancialEntry $entry, FinancialService $financial)
    {
        $financial->cancelManual($entry);

        return redirect()->route('finance.entries.show',$entry)->with('success','Lançamento cancelado.');
    }

    public function settings()
    {
        return view('finance.settings',[
            'categories'=>FinancialCategory::query()->orderBy('type')->orderBy('name')->get(),
            'accounts'=>$this->accountsWithBalance(false),
        ]);
    }

    public function storeCategory(Request $request)
    {
        $data=$request->validate([
            'name'=>['required','string','max:120'],
            'type'=>['required','in:income,expense'],
        ]);

        FinancialCategory::query()->firstOrCreate(
            ['name'=>trim($data['name']),'type'=>$data['type']],
            ['is_active'=>true],
        );

        return redirect()->route('finance.settings')->with('success','Categoria salva.');
    }

    public function toggleCategory(FinancialCategory $category)
    {
        $category->update(['is_active'=>!$category->is_active]);

        return redirect()->route('finance.settings')->with('success','Categoria atualizada.');
    }

    public function storeAccount(Request $request)
    {
        $data=$request->validate([
            'name'=>['required','string','max:120','unique:financial_accounts,name'],
            'type'=>['required','in:cash,bank,digital,other'],
            'opening_balance'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
        ]);

        $data['is_active']=true;
        FinancialAccount::query()->create($data);

        return redirect()->route('finance.settings')->with('success','Conta financeira criada.');
    }

    public function toggleAccount(FinancialAccount $account)
    {
        $account->update(['is_active'=>!$account->is_active]);

        return redirect()->route('finance.settings')->with('success','Conta financeira atualizada.');
    }

    private function validatedEntry(Request $request): array
    {
        $data=$request->validate([
            'type'=>['required','in:receivable,payable'],
            'category_id'=>['required','integer','exists:financial_categories,id'],
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'description'=>['required','string','max:190'],
            'document_number'=>['nullable','string','max:80'],
            'issue_date'=>['required','date'],
            'due_date'=>['required','date'],
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'payment_method'=>['nullable',Rule::in(array_keys(self::PAYMENT_METHODS))],
            'notes'=>['nullable','string','max:5000'],
        ]);

        $category=FinancialCategory::query()->findOrFail((int)$data['category_id']);
        $expected=$data['type']==='receivable' ? 'income' : 'expense';

        if($category->type!==$expected) {
            throw ValidationException::withMessages([
                'category_id'=>$data['type']==='receivable'
                    ? 'Selecione uma categoria de receita.'
                    : 'Selecione uma categoria de despesa.',
            ]);
        }

        return $data;
    }

    private function formData(FinancialEntry $entry, bool $editing): array
    {
        return [
            'entry'=>$entry,
            'editing'=>$editing,
            'categories'=>FinancialCategory::query()->where('is_active',true)->orderBy('type')->orderBy('name')->get(),
            'customers'=>Customer::query()->orderBy('name')->get(['id','name','document','is_customer','is_supplier']),
            'paymentMethods'=>self::PAYMENT_METHODS,
        ];
    }

    private function period(Request $request): array
    {
        $month=(string)$request->query('month',now()->format('Y-m'));

        try {
            $period=Carbon::createFromFormat('Y-m',$month)->startOfMonth();
        } catch (\Throwable) {
            $period=now()->startOfMonth();
        }

        return [
            $period,
            $period->format('Y-m'),
            $period->copy()->subMonth()->format('Y-m'),
            $period->copy()->addMonth()->format('Y-m'),
            ucfirst($period->locale('pt_BR')->translatedFormat('F Y')),
        ];
    }

    private function openBalanceQuery(string $type)
    {
        return FinancialEntry::query()
            ->where('type',$type)
            ->whereIn('status',['open','partial']);
    }

    private function accountsWithBalance(bool $activeOnly=true)
    {
        $accounts=FinancialAccount::query()
            ->when($activeOnly,fn($q)=>$q->where('is_active',true))
            ->orderBy('name')
            ->get();

        foreach($accounts as $account) {
            $movement=FinancialSettlement::query()
                ->join('financial_entries','financial_entries.id','=','financial_settlements.financial_entry_id')
                ->where('financial_settlements.financial_account_id',$account->id)
                ->whereNull('financial_settlements.reversed_at')
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN financial_entries.type='receivable' THEN financial_settlements.amount ELSE -financial_settlements.amount END),0) total"
                )
                ->value('total');

            $account->setAttribute(
                'current_balance',
                round((float)$account->opening_balance+(float)$movement,2)
            );
        }

        return $accounts;
    }
}
