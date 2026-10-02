<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialRecurrence;
use App\Models\PaymentMethod;
use App\Services\FinancialRecurrenceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialRecurrenceController extends Controller
{
    public function index(Request $request)
    {
        $term=trim((string)$request->query('search',''));
        $type=in_array($request->query('type'),['receivable','payable'],true)?(string)$request->query('type'):'';
        $status=in_array($request->query('status'),['active','paused'],true)?(string)$request->query('status'):'';

        $query=FinancialRecurrence::query()
            ->with(['customer','category','account'])
            ->when($term,fn($q)=>$q->where(function($search) use($term){
                $search->where('description','like',"%{$term}%")
                    ->orWhere('keywords','like',"%{$term}%")
                    ->orWhereHas('customer',fn($customer)=>$customer->where('name','like',"%{$term}%"));
            }))
            ->when($type,fn($q)=>$q->where('type',$type))
            ->when($status==='active',fn($q)=>$q->where('is_active',true))
            ->when($status==='paused',fn($q)=>$q->where('is_active',false));

        $activeCount=(clone $query)->where('is_active',true)->count();
        $monthlyBase=(float)(clone $query)->where('is_active',true)->where('frequency','monthly')->sum('amount');

        $perPage=AppSetting::tablePerPage($request);

        return view('finance.recurrences.index',[
            'recurrences'=>$query->orderByDesc('is_active')->orderBy('next_date')->paginate($perPage)->withQueryString(),
            'term'=>$term,'type'=>$type,'status'=>$status,'activeCount'=>$activeCount,'monthlyBase'=>$monthlyBase,
        ]);
    }

    public function create()
    {
        return view('finance.recurrences.form',[
            'categories'=>FinancialCategory::query()->where('is_active',true)->orderBy('type')->orderBy('name')->get(),
            'customers'=>Customer::query()->orderBy('name')->get(['id','name','document']),
            'accounts'=>FinancialAccount::query()->where('is_active',true)->orderBy('name')->get(),
            'paymentMethods'=>PaymentMethod::options(),
        ]);
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'type'=>['required','in:receivable,payable'],
            'description'=>['required','string','max:190'],
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'category_id'=>['required','integer','exists:financial_categories,id'],
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],
            'frequency'=>['required','in:weekly,monthly,yearly'],
            'interval_count'=>['required','integer','min:1','max:24'],
            'start_date'=>['required','date'],
            'end_date'=>['nullable','date','after_or_equal:start_date'],
            'payment_method'=>['nullable',Rule::in(array_keys(PaymentMethod::options()))],
            'keywords'=>['nullable','string','max:255'],
            'notes'=>['nullable','string','max:2000'],
        ]);

        $category=FinancialCategory::findOrFail($data['category_id']);
        $expected=$data['type']==='receivable'?'income':'expense';
        if($category->type!==$expected) throw ValidationException::withMessages(['category_id'=>'Categoria incompatível com o tipo da recorrência.']);

        $data['next_date']=$data['start_date'];
        $data['created_by']=$request->user()->id;
        $data['is_active']=true;
        FinancialRecurrence::query()->create($data);

        return redirect()->route('finance.recurrences.index')->with('success','Recorrência criada.');
    }

    public function generate(FinancialRecurrenceService $service)
    {
        $count=$service->generateDue();
        return back()->with('success',$count.' lançamento(s) recorrente(s) gerado(s).');
    }

    public function toggle(FinancialRecurrence $recurrence)
    {
        $recurrence->update(['is_active'=>!$recurrence->is_active]);
        return back()->with('success','Recorrência atualizada.');
    }
}
