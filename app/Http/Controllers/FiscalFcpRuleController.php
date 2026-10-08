<?php

namespace App\Http\Controllers;

use App\Models\FiscalFcpRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FiscalFcpRuleController extends Controller
{
    private const UFS=[
        'AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT',
        'PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO',
    ];

    public function index()
    {
        return view('fiscal.fcp.index',[
            'rules'=>FiscalFcpRule::query()->orderBy('uf')->orderBy('ncm_prefix')->get(),
            'ufs'=>self::UFS,
        ]);
    }

    public function store(Request $request)
    {
        $data=$this->validated($request);
        FiscalFcpRule::query()->create($data);
        return back()->with('success','Regra FCP cadastrada. A cobrança só será aplicada quando o cálculo tributário correspondente estiver implementado.');
    }

    public function update(Request $request,FiscalFcpRule $fiscalFcpRule)
    {
        $fiscalFcpRule->update($this->validated($request));
        return back()->with('success','Regra FCP atualizada.');
    }

    private function validated(Request $request): array
    {
        $data=$request->validate([
            'uf'=>['required',Rule::in(self::UFS)],
            'ncm_prefix'=>['nullable','regex:/^[0-9]{2,8}$/'],
            'rate'=>['required','numeric','min:0','max:100','decimal:0,4'],
            'valid_from'=>['nullable','date'],
            'valid_until'=>['nullable','date'],
            'notes'=>['nullable','string','max:2000'],
        ]);
        if (!empty($data['valid_from']) && !empty($data['valid_until'])
            && $data['valid_until']<$data['valid_from']) {
            throw ValidationException::withMessages([
                'valid_until'=>'O fim da vigência deve ser posterior ou igual ao início.',
            ]);
        }
        $data['apply_to_own_fcp']=$request->boolean('apply_to_own_fcp');
        $data['is_active']=$request->boolean('is_active');
        $data['ncm_prefix']=$data['ncm_prefix'] ?? null;
        return $data;
    }
}
