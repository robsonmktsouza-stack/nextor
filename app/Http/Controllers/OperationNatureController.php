<?php

namespace App\Http\Controllers;

use App\Models\OperationNature;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationNatureController extends Controller
{
    public function index()
    {
        return view('fiscal.nfe.natures',[
            'natures'=>OperationNature::query()->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        OperationNature::query()->create($this->validated($request));
        return back()->with('success','Natureza de operação cadastrada.');
    }

    public function update(Request $request, OperationNature $operationNature)
    {
        $operationNature->update($this->validated($request));
        return back()->with('success','Natureza de operação atualizada.');
    }

    private function validated(Request $request): array
    {
        $data=$request->validate([
            'name'=>['required','string','max:160'],
            'operation_type'=>['required',Rule::in(['outbound','inbound'])],
            'purpose'=>['required',Rule::in(['normal','complementary','adjustment','return'])],
            'cfop_internal'=>['nullable','string','max:10'],
            'cfop_interstate'=>['nullable','string','max:10'],
            'cfop_inbound_internal'=>['nullable','string','max:10'],
            'cfop_inbound_interstate'=>['nullable','string','max:10'],
            'cfop_foreign'=>['nullable','string','max:10'],
            'additional_info'=>['nullable','string','max:5000'],
            'override_product_cfop'=>['nullable','boolean'],
            'move_stock'=>['nullable','boolean'],
            'is_active'=>['nullable','boolean'],
        ]);

        $data['override_product_cfop']=$request->boolean('override_product_cfop');
        $data['move_stock']=$request->boolean('move_stock');
        $data['is_active']=$request->boolean('is_active');
        $data['presence_default']='presential';

        return $data;
    }
}
