<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use Illuminate\Http\Request;
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
}
