<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
class CustomerController extends Controller {
    public function index(Request $request) {
        $term=trim((string)$request->query('search',''));
        $customers=Customer::query()->when($term,fn($q)=>$q->where(fn($t)=>$t->where('name','like',"%{$term}%")->orWhere('document','like',"%{$term}%")))
            ->orderBy('name')->paginate(12)->withQueryString();
        return view('customers.index',compact('term','customers'));
    }
    private function rules(): array { return [
        'name'=>['required','string','max:190'], 'document'=>['nullable','string','max:20'],
        'email'=>['nullable','email','max:255'], 'phone'=>['nullable','string','max:25'],
        'notes'=>['nullable','string','max:5000'],
    ]; }
    public function store(Request $request) {
        Customer::create($request->validate($this->rules()));
        return redirect()->route('customers.index')->with('success','Cliente cadastrado.');
    }
    public function update(Request $request,Customer $customer) {
        $customer->update($request->validate($this->rules()));
        return redirect()->route('customers.index')->with('success','Cliente atualizado.');
    }

    public function bulkDuplicate(Request $request) {
        $ids=$request->validate(['ids'=>['required','array','min:1'],'ids.*'=>['integer','exists:customers,id']])['ids'];
        $count=0;
        DB::transaction(function() use ($ids,&$count) {
            Customer::whereIn('id',$ids)->orderBy('id')->get()->each(function(Customer $customer) use (&$count) {
                $copy=$customer->replicate();
                $copy->name=$customer->name.' (cópia)';
                $copy->document=null;
                $copy->save();
                $count++;
            });
        });
        return redirect()->route('customers.index')->with('success',"{$count} cliente(s) duplicado(s).");
    }

    public function bulkDelete(Request $request) {
        $ids=$request->validate(['ids'=>['required','array','min:1'],'ids.*'=>['integer','exists:customers,id']])['ids'];
        $deleted=0;$blocked=0;
        DB::transaction(function() use ($ids,&$deleted,&$blocked) {
            Customer::whereIn('id',$ids)->get()->each(function(Customer $customer) use (&$deleted,&$blocked) {
                if (Sale::where('customer_id',$customer->id)->exists()) { $blocked++; return; }
                $customer->delete(); $deleted++;
            });
        });
        $message="{$deleted} cliente(s) excluído(s).";
        if($blocked) $message.=" {$blocked} não foram excluídos porque possuem vendas vinculadas.";
        return redirect()->route('customers.index')->with('success',$message);
    }
}
