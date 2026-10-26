<?php

namespace App\Http\Controllers;

use App\Models\OperationNature;
use App\Services\Fiscal\FiscalCatalogModelReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationNatureController extends Controller
{
    public function index()
    {
        return view('fiscal.nfe.natures',[
            'natures'=>OperationNature::query()->with(['revisions'])->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        DB::transaction(function () use ($request): void {
            $nature=OperationNature::query()->create($this->validated($request));
            app(FiscalCatalogModelReviewService::class)->record($nature,$request->user()?->id);
        });
        return back()->with('success','Natureza de operação cadastrada.');
    }

    public function update(Request $request, OperationNature $operationNature)
    {
        $data=$this->validated($request);
        DB::transaction(function () use ($request,$operationNature,$data): void {
            $nature=OperationNature::query()->lockForUpdate()->findOrFail($operationNature->id);
            $review=app(FiscalCatalogModelReviewService::class);
            $data=$review->apply($request,$nature,$data);
            $review->record($nature,$request->user()?->id);
            $data['revision']=$nature->revision+1;
            $nature->update($data);
            $review->record($nature,$request->user()?->id);
        });
        return back()->with('success','Natureza de operação atualizada.');
    }

    private function validated(Request $request): array
    {
        $data=$request->validate([
            'name'=>['required','string','max:160'],
            'operation_type'=>['required',Rule::in(['outbound','inbound'])],
            'purpose'=>['required',Rule::in(['normal','complementary','adjustment','return','credit_note','debit_note'])],
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

        return $data;
    }
}
