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
            'cfop_foreign'=>['nullable','string','max:10'],
            'presence_default'=>['required',Rule::in(['not_applicable','presential','internet','phone','outside_establishment','other'])],
            'additional_info'=>['nullable','string','max:5000'],
            'tax_authority_info'=>['nullable','string','max:5000'],
            'override_product_cfop'=>['nullable','boolean'],
            'final_consumer_default'=>['nullable','boolean'],
            'move_stock'=>['nullable','boolean'],
            'generate_finance'=>['nullable','boolean'],
            'allow_referenced_document'=>['nullable','boolean'],
            'require_transport'=>['nullable','boolean'],
            'require_invoice'=>['nullable','boolean'],
            'require_duplicates'=>['nullable','boolean'],
            'is_active'=>['nullable','boolean'],
        ]);

        foreach([
            'override_product_cfop','final_consumer_default','move_stock','generate_finance',
            'allow_referenced_document','require_transport','require_invoice','require_duplicates','is_active'
        ] as $key) {
            $data[$key]=$request->boolean($key);
        }

        return $data;
    }
}
