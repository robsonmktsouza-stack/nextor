<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialRecurrence;
use App\Services\FinancialRecurrenceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialRecurrenceController extends Controller
{
    public function index()
    {
        return view('finance.recurrences.index',[
            'recurrences'=>FinancialRecurrence::query()->with(['customer','category','account'])->orderByDesc('is_active')->orderBy('next_date')->paginate(25),
        ]);
    }

    public function create()
    {
        return view('finance.recurrences.form',[
            'categories'=>FinancialCategory::query()->where('is_active',true)->orderBy('type')->orderBy('name')->get(),
            'customers'=>Customer::query()->orderBy('name')->get(['id','name','document']),
            'accounts'=>FinancialAccount::query()->where('is_active',true)->orderBy('name')->get(),
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
            'payment_method'=>['nullable',Rule::in(['cash','pix','debit_card','credit_card','bank_slip','bank_transfer','other'])],
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
