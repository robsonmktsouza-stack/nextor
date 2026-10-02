<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\FinancialSettlement;
use App\Models\PaymentMethod;
use App\Services\BillingService;
use App\Services\FinancialBalanceService;
use App\Services\FinancialService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    public const PAYMENT_METHODS=[
        'cash'=>'Dinheiro','pix'=>'PIX','debit_card'=>'Cartão de débito','credit_card'=>'Cartão de crédito',
        'bank_slip'=>'Boleto','bank_transfer'=>'Transferência','other'=>'Outro',
    ];

    public function dashboard(Request $request, FinancialBalanceService $balances)
    {
        [$period,$month,$prevMonth,$nextMonth,$monthLabel]=$this->period($request);
        $start=$period->copy()->startOfMonth()->toDateString();
        $end=$period->copy()->endOfMonth()->toDateString();

        $settled=FinancialSettlement::query()->whereNull('reversed_at')->whereBetween('settled_at',[$start,$end]);
        $received=(clone $settled)->whereHas('entry',fn($q)=>$q->where('type','receivable'))->sum('amount');
        $paid=(clone $settled)->whereHas('entry',fn($q)=>$q->where('type','payable'))->sum('amount');
        $receivable=$this->openBalanceQuery('receivable')->whereBetween('due_date',[$start,$end])->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')->value('total');
        $payable=$this->openBalanceQuery('payable')->whereBetween('due_date',[$start,$end])->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')->value('total');
        $overdueReceivable=$this->openBalanceQuery('receivable')->whereDate('due_date','<',today())->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')->value('total');
        $overduePayable=$this->openBalanceQuery('payable')->whereDate('due_date','<',today())->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')->value('total');

        $cashFlow=FinancialSettlement::query()
            ->join('financial_entries','financial_entries.id','=','financial_settlements.financial_entry_id')
            ->whereNull('financial_settlements.reversed_at')->whereBetween('financial_settlements.settled_at',[$start,$end])
            ->groupBy('financial_settlements.settled_at')->orderBy('financial_settlements.settled_at')
            ->selectRaw("financial_settlements.settled_at as day,
                SUM(CASE WHEN financial_entries.type='receivable' THEN financial_settlements.amount ELSE 0 END) as income,
                SUM(CASE WHEN financial_entries.type='payable' THEN financial_settlements.amount ELSE 0 END) as expense")->get();

        $upcoming=FinancialEntry::query()->with(['category','customer'])->whereIn('status',['open','partial'])
            ->whereBetween('due_date',[today()->toDateString(),today()->copy()->addDays(7)->toDateString()])->orderBy('due_date')->limit(8)->get();
        $recentEntries=FinancialEntry::query()->with(['category','customer'])->latest('id')->limit(8)->get();
        $accounts=$balances->accounts();

        return view('finance.dashboard',compact(
            'month','prevMonth','nextMonth','monthLabel','received','paid','receivable','payable',
            'overdueReceivable','overduePayable','cashFlow','upcoming','recentEntries','accounts'
        ));
    }

    public function entries(Request $request)
    {
        [$period,$month,$prevMonth,$nextMonth,$monthLabel]=$this->period($request);
        $type=in_array($request->query('type'),['receivable','payable'],true)?(string)$request->query('type'):'';
        $status=(string)$request->query('status','');
        $term=trim((string)$request->query('search',''));
        $categoryId=$request->integer('category_id');
        $requestedPerPage=$request->integer('per_page');
        if(in_array($requestedPerPage,[10,25,50,100],true)) $request->session()->put('table_per_page',$requestedPerPage);
        $defaultPerPage=(int)AppSetting::value('system','rows_per_page',25);
        if(!in_array($defaultPerPage,[10,25,50,100],true)) $defaultPerPage=25;
        $perPage=(int)$request->session()->get('table_per_page',$defaultPerPage);
        if(!in_array($perPage,[10,25,50,100],true)) $perPage=$defaultPerPage;

        $query=FinancialEntry::query()->with(['category','account','customer','sale'])
            ->whereYear('due_date',$period->year)->whereMonth('due_date',$period->month)
            ->when($type,fn($q)=>$q->where('type',$type))
            ->when($categoryId,fn($q)=>$q->where('category_id',$categoryId))
            ->when($term,fn($q)=>$q->where(function($search) use($term){
                $search->where('description','like',"%{$term}%")->orWhere('document_number','like',"%{$term}%")
                    ->orWhere('keywords','like',"%{$term}%")
                    ->orWhereHas('customer',fn($party)=>$party->where('name','like',"%{$term}%")->orWhere('document','like',"%{$term}%"));
            }));

        if($status==='overdue') $query->whereIn('status',['open','partial'])->whereDate('due_date','<',today());
        elseif(in_array($status,['open','partial','paid','cancelled'],true)) $query->where('status',$status);

        $totalAmount=(clone $query)->where('status','!=','cancelled')->sum('amount');
        $openAmount=(clone $query)->whereIn('status',['open','partial'])->selectRaw('COALESCE(SUM(amount-paid_amount),0) total')->value('total');
        $entries=$query->orderBy('due_date')->orderBy('id')->paginate($perPage)->withQueryString();

        $categories=FinancialCategory::query()->where('is_active',true)
            ->when($type==='receivable',fn($q)=>$q->where('type','income'))
            ->when($type==='payable',fn($q)=>$q->where('type','expense'))->orderBy('name')->get();

        $accounts=FinancialAccount::query()->where('is_active',true)->orderBy('name')->get();

        return view('finance.index',compact(
            'entries','type','status','term','categoryId','categories','accounts','month','prevMonth','nextMonth',
            'monthLabel','totalAmount','openAmount'
        ))->with('paymentMethods',$this->paymentMethods());
    }

    public function create(Request $request)
    {
        $type=in_array($request->query('type'),['receivable','payable'],true)?(string)$request->query('type'):'receivable';
        $categorySetting=$type==='receivable'?'default_income_category_id':'default_expense_category_id';
        $categoryId=(int)AppSetting::value('operations',$categorySetting,0);
        $accountId=(int)AppSetting::value('operations','default_financial_account_id',0);
        $dueDays=max(0,(int)AppSetting::value('operations','default_due_days',0));

        if($type==='receivable' && (bool)AppSetting::value('billing','enabled',false)) {
            $dueDays=max(0,(int)AppSetting::value('billing','default_due_days',$dueDays));
            $billingAccount=(int)AppSetting::value('billing','default_financial_account_id',0);
            if($billingAccount>0) $accountId=$billingAccount;
        }

        $entry=new FinancialEntry([
            'type'=>$type,'status'=>'open','issue_date'=>today(),'competence_date'=>today(),
            'due_date'=>today()->copy()->addDays($dueDays),'amount'=>'0.00','paid_amount'=>'0.00',
            'category_id'=>$categoryId ?: null,'financial_account_id'=>$accountId ?: null,
        ]);
        return view('finance.form',$this->formData($entry,false));
    }

    public function store(Request $request, FinancialService $financial)
    {
        $data=$this->validatedEntry($request);
        $settleNow=$request->boolean('settle_now');
        $settledAt=$request->input('settled_at') ?: $data['issue_date'];

        if($settleNow && empty($data['financial_account_id'])) {
            throw ValidationException::withMessages([
                'financial_account_id'=>'Selecione a conta para registrar a baixa agora.'
            ]);
        }

        $data['status']='open';$data['paid_amount']='0.00';$data['created_by']=$request->user()->id;

        if($request->hasFile('attachment')) {
            $file=$request->file('attachment');
            $data['attachment_name']=$file->getClientOriginalName();
            $data['attachment_path']=$file->store('finance/entries','local');
        }
        unset($data['attachment']);

        $entry=FinancialEntry::query()->create($data);

        if($settleNow) {
            $financial->settle($entry,[
                'amount'=>$entry->amount,'settled_at'=>$settledAt,'financial_account_id'=>$entry->financial_account_id,
                'payment_method'=>$entry->payment_method ?: $this->defaultPaymentMethod(),'notes'=>'Baixa registrada junto com o lançamento.',
            ],(int)$request->user()->id);
        }

        return redirect()->route('finance.entries.show',$entry)->with('success','Lançamento financeiro criado.');
    }

    public function show(FinancialEntry $entry, BillingService $billing)
    {
        $entry->load(['category','account','customer','sale','salePayment','recurrence','creator','settlements.account','settlements.user']);
        return view('finance.show',[
            'entry'=>$entry,
            'accounts'=>FinancialAccount::query()->where('is_active',true)->orderBy('name')->get(),
            'paymentMethods'=>$this->paymentMethods(),
            'billingSummary'=>$billing->summary($entry),
        ]);
    }

    public function edit(FinancialEntry $entry)
    {
        if($entry->sale_id!==null) return redirect()->route('finance.entries.show',$entry)->with('warning','Lançamentos gerados por venda não são editados diretamente.');
        if($entry->status==='cancelled') return redirect()->route('finance.entries.show',$entry)->with('warning','Lançamento cancelado não pode ser editado.');
        return view('finance.form',$this->formData($entry,true));
    }

    public function update(Request $request, FinancialEntry $entry)
    {
        if($entry->sale_id!==null) throw ValidationException::withMessages(['entry'=>'Lançamentos originados de venda não podem ser editados diretamente.']);
        if($entry->status==='cancelled') throw ValidationException::withMessages(['entry'=>'Lançamento cancelado não pode ser editado.']);

        $data=$this->validatedEntry($request);
        if((float)$data['amount']+0.0001<(float)$entry->paid_amount) throw ValidationException::withMessages(['amount'=>'O valor não pode ser menor que o total já baixado.']);

        if($request->hasFile('attachment')) {
            if($entry->attachment_path) Storage::disk('local')->delete($entry->attachment_path);
            $file=$request->file('attachment');
            $data['attachment_name']=$file->getClientOriginalName();
            $data['attachment_path']=$file->store('finance/entries','local');
        }
        unset($data['attachment']);

        $entry->fill($data);
        $entry->status=(float)$entry->paid_amount<=0?'open':((float)$entry->paid_amount+0.0001>=(float)$entry->amount?'paid':'partial');
        $entry->save();

        return redirect()->route('finance.entries.show',$entry)->with('success','Lançamento atualizado.');
    }

    public function attachment(FinancialEntry $entry)
    {
        abort_unless($entry->attachment_path && Storage::disk('local')->exists($entry->attachment_path),404);
        return Storage::disk('local')->download($entry->attachment_path,$entry->attachment_name ?: basename($entry->attachment_path));
    }

    public function settle(Request $request, FinancialEntry $entry, FinancialService $financial)
    {
        $data=$request->validate([
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],'settled_at'=>['required','date'],
            'financial_account_id'=>['required','integer','exists:financial_accounts,id'],
            'payment_method'=>['required',Rule::in(array_keys($this->paymentMethods()))],'notes'=>['nullable','string','max:1000'],
        ]);
        $financial->settle($entry,$data,(int)$request->user()->id);
        return redirect()->route('finance.entries.show',$entry)->with('success','Baixa registrada.');
    }

    public function reverseSettlement(Request $request, FinancialSettlement $settlement, FinancialService $financial)
    {
        $data=$request->validate(['reason'=>['nullable','string','max:500']]);
        $entryId=$settlement->financial_entry_id;
        $financial->reverse($settlement,$data['reason'] ?? null);
        return redirect()->route('finance.entries.show',$entryId)->with('success','Baixa estornada.');
    }

    public function cancel(FinancialEntry $entry, FinancialService $financial)
    {
        $financial->cancelManual($entry);
        return redirect()->route('finance.entries.show',$entry)->with('success','Lançamento cancelado.');
    }

    public function bulkAction(Request $request, FinancialService $financial)
    {
        $data=$request->validate([
            'ids'=>['required','array','min:1','max:200'],
            'ids.*'=>['required','integer','distinct','exists:financial_entries,id'],
            'action'=>['required',Rule::in(['settle','settle_quick','cancel','reopen','edit_primary','edit_aux'])],
            'financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],
            'settled_at'=>['nullable','date'],
            'payment_method'=>['nullable',Rule::in(array_keys($this->paymentMethods()))],
            'description'=>['nullable','string','max:190'],
            'due_date'=>['nullable','date'],
            'amount'=>['nullable','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'category_id'=>['nullable','integer','exists:financial_categories,id'],
            'keywords'=>['nullable','string','max:255'],
            'clear_keywords'=>['nullable','boolean'],
            'clear_payment_method'=>['nullable','boolean'],
        ]);

        $ids=array_values(array_unique(array_map('intval',$data['ids'])));
        $entries=FinancialEntry::query()
            ->withCount('activeSettlements')
            ->whereIn('id',$ids)
            ->orderBy('id')
            ->get();

        $applied=0;
        $skipped=0;

        if($data['action']==='settle') {
            if(empty($data['financial_account_id'])) {
                throw ValidationException::withMessages(['financial_account_id'=>'Selecione a conta financeira para a baixa em massa.']);
            }
            if(empty($data['settled_at'])) {
                throw ValidationException::withMessages(['settled_at'=>'Informe a data da baixa em massa.']);
            }
            if(empty($data['payment_method'])) {
                throw ValidationException::withMessages(['payment_method'=>'Selecione a forma de pagamento da baixa em massa.']);
            }

            $account=FinancialAccount::query()
                ->whereKey((int)$data['financial_account_id'])
                ->where('is_active',true)
                ->first();

            if(!$account) {
                throw ValidationException::withMessages(['financial_account_id'=>'Conta financeira inválida ou inativa.']);
            }

            foreach($entries as $entry) {
                if(!in_array($entry->status,['open','partial'],true) || $entry->balance<=0) {
                    $skipped++;
                    continue;
                }

                $financial->settle($entry,[
                    'amount'=>number_format($entry->balance,2,'.',''),
                    'settled_at'=>$data['settled_at'],
                    'financial_account_id'=>$account->id,
                    'payment_method'=>$data['payment_method'],
                    'notes'=>'Baixa integral registrada por ação em massa.',
                ],(int)$request->user()->id);
                $applied++;
            }

            $message=$applied.' lançamento(s) baixado(s) integralmente.';
        } elseif($data['action']==='settle_quick') {
            foreach($entries as $entry) {
                if(
                    !in_array($entry->status,['open','partial'],true)
                    || $entry->balance<=0
                    || !$entry->financial_account_id
                    || !$entry->payment_method
                ) {
                    $skipped++;
                    continue;
                }

                $financial->settle($entry,[
                    'amount'=>number_format($entry->balance,2,'.',''),
                    'settled_at'=>today()->toDateString(),
                    'financial_account_id'=>$entry->financial_account_id,
                    'payment_method'=>$entry->payment_method,
                    'notes'=>'Baixa integral rápida registrada por ação em massa.',
                ],(int)$request->user()->id);
                $applied++;
            }

            $message=$applied.' lançamento(s) baixado(s) usando conta e forma já cadastradas.';
        } elseif($data['action']==='edit_primary') {
            $hasChange=
                filled($data['description'] ?? null)
                || filled($data['due_date'] ?? null)
                || filled($data['amount'] ?? null)
                || filled($data['category_id'] ?? null)
                || filled($data['financial_account_id'] ?? null);

            if(!$hasChange) {
                throw ValidationException::withMessages(['action'=>'Informe pelo menos um campo para alterar.']);
            }

            $category=!empty($data['category_id'])
                ? FinancialCategory::query()->findOrFail((int)$data['category_id'])
                : null;

            foreach($entries as $entry) {
                if($entry->sale_id!==null || $entry->status==='cancelled') {
                    $skipped++;
                    continue;
                }

                if(isset($data['amount']) && (float)$data['amount']+0.0001<(float)$entry->paid_amount) {
                    $skipped++;
                    continue;
                }

                if($category) {
                    $expected=$entry->type==='receivable'?'income':'expense';
                    if($category->type!==$expected) {
                        $skipped++;
                        continue;
                    }
                }

                $updates=[];
                if(filled($data['description'] ?? null)) $updates['description']=trim((string)$data['description']);
                if(filled($data['due_date'] ?? null)) $updates['due_date']=$data['due_date'];
                if(filled($data['amount'] ?? null)) $updates['amount']=$data['amount'];
                if($category) $updates['category_id']=$category->id;
                if(filled($data['financial_account_id'] ?? null)) $updates['financial_account_id']=(int)$data['financial_account_id'];

                if($updates) {
                    $entry->update($updates);
                    $entry->refresh();
                    if((float)$entry->paid_amount>0) {
                        $entry->update([
                            'status'=>(float)$entry->paid_amount+0.0001>=(float)$entry->amount?'paid':'partial',
                        ]);
                    }
                    $applied++;
                }
            }

            $message=$applied.' lançamento(s) atualizado(s).';
        } elseif($data['action']==='edit_aux') {
            $changeKeywords=filled($data['keywords'] ?? null) || $request->boolean('clear_keywords');
            $changePayment=filled($data['payment_method'] ?? null) || $request->boolean('clear_payment_method');

            if(!$changeKeywords && !$changePayment) {
                throw ValidationException::withMessages(['action'=>'Informe palavras-chave ou forma de pagamento para alterar.']);
            }

            foreach($entries as $entry) {
                if($entry->sale_id!==null || $entry->status==='cancelled') {
                    $skipped++;
                    continue;
                }

                $updates=[];
                if($changeKeywords) $updates['keywords']=$request->boolean('clear_keywords') ? null : trim((string)($data['keywords'] ?? ''));
                if($changePayment) $updates['payment_method']=$request->boolean('clear_payment_method') ? null : ($data['payment_method'] ?? null);

                if($updates) {
                    $entry->update($updates);
                    $applied++;
                }
            }

            $message=$applied.' lançamento(s) atualizado(s).';
        } elseif($data['action']==='cancel') {
            foreach($entries as $entry) {
                if($entry->sale_id!==null || $entry->status==='cancelled' || $entry->active_settlements_count>0) {
                    $skipped++;
                    continue;
                }

                $financial->cancelManual($entry);
                $applied++;
            }

            $message=$applied.' lançamento(s) cancelado(s).';
        } else {
            foreach($entries as $entry) {
                if($entry->sale_id!==null || $entry->status!=='cancelled') {
                    $skipped++;
                    continue;
                }

                $financial->reopenManual($entry);
                $applied++;
            }

            $message=$applied.' lançamento(s) reaberto(s).';
        }

        $redirect=back()->with('success',$message);
        if($skipped>0) {
            $redirect->with('warning',$skipped.' item(ns) foram ignorados porque não permitem esta ação.');
        }

        return $redirect;
    }

    public function settings(FinancialBalanceService $balances)
    {
        return redirect()->route('settings.index',['tab'=>'chart']);
    }

    public function storeCategory(Request $request)
    {
        $data=$request->validate(['name'=>['required','string','max:120'],'type'=>['required','in:income,expense']]);
        FinancialCategory::query()->firstOrCreate(['name'=>trim($data['name']),'type'=>$data['type']],['is_active'=>true]);
        return redirect()->route('settings.index',['tab'=>'chart'])->with('success','Categoria salva.');
    }

    public function toggleCategory(FinancialCategory $category)
    {
        $category->update(['is_active'=>!$category->is_active]);
        return redirect()->route('settings.index',['tab'=>'chart'])->with('success','Categoria atualizada.');
    }

    public function storeAccount(Request $request)
    {
        $data=$request->validate([
            'name'=>['required','string','max:120','unique:financial_accounts,name'],'type'=>['required','in:cash,bank,digital,other'],
            'opening_balance'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
        ]);
        $data['is_active']=true;FinancialAccount::query()->create($data);
        return redirect()->route('settings.index',['tab'=>'accounts'])->with('success','Conta financeira criada.');
    }

    public function toggleAccount(FinancialAccount $account)
    {
        $account->update(['is_active'=>!$account->is_active]);
        return redirect()->route('settings.index',['tab'=>'accounts'])->with('success','Conta financeira atualizada.');
    }

    private function validatedEntry(Request $request): array
    {
        $data=$request->validate([
            'type'=>['required','in:receivable,payable'],'category_id'=>['required','integer','exists:financial_categories,id'],
            'financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],'customer_id'=>['nullable','integer','exists:customers,id'],
            'description'=>['required','string','max:190'],'document_number'=>['nullable','string','max:80'],
            'issue_date'=>['required','date'],'competence_date'=>['nullable','date'],'due_date'=>['required','date'],'credit_date'=>['nullable','date'],
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'payment_method'=>['nullable',Rule::in(array_keys($this->paymentMethods()))],'keywords'=>['nullable','string','max:255'],
            'cost_center'=>['nullable','string','max:120'],
            'notes'=>['nullable','string','max:5000'],'attachment'=>['nullable','file','max:10240'],
        ]);

        $category=FinancialCategory::query()->findOrFail((int)$data['category_id']);
        $expected=$data['type']==='receivable'?'income':'expense';
        if($category->type!==$expected) throw ValidationException::withMessages(['category_id'=>$data['type']==='receivable'?'Selecione uma categoria de receita.':'Selecione uma categoria de despesa.']);
        $data['competence_date']=$data['competence_date'] ?: $data['issue_date'];
        if(!(bool)AppSetting::value('accounting','cost_center_enabled',false)) {
            $data['cost_center']=null;
        }
        return $data;
    }

    private function formData(FinancialEntry $entry,bool $editing): array
    {
        return [
            'entry'=>$entry,'editing'=>$editing,
            'categories'=>FinancialCategory::query()->where('is_active',true)->orderBy('type')->orderBy('name')->get(),
            'customers'=>Customer::query()->orderBy('name')->get(['id','name','document','is_customer','is_supplier']),
            'accounts'=>FinancialAccount::query()->where('is_active',true)->orderBy('name')->get(),
            'paymentMethods'=>$this->paymentMethods(),
            'costCenterEnabled'=>(bool)AppSetting::value('accounting','cost_center_enabled',false),
        ];
    }

    private function paymentMethods(): array
    {
        $methods=PaymentMethod::options();
        return $methods ?: self::PAYMENT_METHODS;
    }

    private function defaultPaymentMethod(): string
    {
        $methods=$this->paymentMethods();
        if(isset($methods['other'])) return 'other';
        return (string)(array_key_first($methods) ?: 'other');
    }

    private function period(Request $request): array
    {
        $month=(string)$request->query('month',now()->format('Y-m'));
        try{$period=Carbon::createFromFormat('Y-m',$month)->startOfMonth();}catch(\Throwable){$period=now()->startOfMonth();}
        return [$period,$period->format('Y-m'),$period->copy()->subMonth()->format('Y-m'),$period->copy()->addMonth()->format('Y-m'),ucfirst($period->locale('pt_BR')->translatedFormat('F Y'))];
    }

    private function openBalanceQuery(string $type)
    {
        return FinancialEntry::query()->where('type',$type)->whereIn('status',['open','partial']);
    }
}
