<?php

namespace App\Http\Controllers;

use App\Models\FiscalTaxGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FiscalTaxGroupController extends Controller
{
    private const RATE_FIELDS = [
        'icms_rate','credit_rate','icms_st_rate','mva_rate',
        'pis_rate','cofins_rate','ipi_rate','iss_rate','is_rate',
        'cbs_rate','ibs_uf_rate','ibs_municipal_rate',
        'cbs_reduction_rate','ibs_reduction_rate',
    ];

    private const TEXT_FIELDS = [
        'ibs_cbs_cst' => 3,
        'ibs_cbs_class' => 6,
        'is_cst' => 3,
        'is_class' => 6,
        'nfce_note' => 255,
    ];

    public function index()
    {
        return view('fiscal.tax-groups.index', [
            'groups' => FiscalTaxGroup::query()
                ->withCount(['products','services'])
                ->orderBy('kind')->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        return view('fiscal.tax-groups.form', [
            'group'=>new FiscalTaxGroup([
                'kind'=>$request->query('kind')==='services' ? 'services' : 'products',
                'tax_config'=>[],
            ]),
            'editing'=>false,
        ]);
    }

    public function edit(FiscalTaxGroup $fiscalTaxGroup)
    {
        return view('fiscal.tax-groups.form',[
            'group'=>$fiscalTaxGroup,
            'editing'=>true,
        ]);
    }

    public function store(Request $request)
    {
        $data=$this->validated($request);
        DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                FiscalTaxGroup::query()->where('kind',$data['kind'])->update(['is_default'=>false]);
            }
            FiscalTaxGroup::query()->create($data);
        });

        return redirect()->route('fiscal.tax-groups.index')
            ->with('success','Grupo de tributação criado. Enquadre os produtos e revise os tributos antes de habilitar emissão.');
    }

    public function update(Request $request,FiscalTaxGroup $fiscalTaxGroup)
    {
        $data=$this->validated($request);
        if ($data['kind'] !== $fiscalTaxGroup->kind
            && ($fiscalTaxGroup->products()->exists() || $fiscalTaxGroup->services()->exists())) {
            throw ValidationException::withMessages([
                'kind'=>'O tipo do grupo não pode mudar enquanto estiver vinculado a produtos ou serviços.',
            ]);
        }
        DB::transaction(function () use ($data,$fiscalTaxGroup) {
            $group=FiscalTaxGroup::query()->lockForUpdate()->findOrFail($fiscalTaxGroup->id);
            if ($data['is_default']) {
                FiscalTaxGroup::query()
                    ->where('kind',$data['kind'])
                    ->whereKeyNot($group->id)
                    ->update(['is_default'=>false]);
            }
            $data['revision']=$group->revision+1;
            $group->update($data);
        });

        return redirect()->route('fiscal.tax-groups.index')
            ->with('success','Grupo de tributação atualizado. Documentos já assinados não são alterados.');
    }

    private function validated(Request $request): array
    {
        $fields=[
            'name'=>['required','string','max:160'],
            'kind'=>['required',Rule::in(['products','services'])],
            'cfop_pattern'=>['nullable','regex:/^(?:[567][0-9]{3}|x[0-9]{3})$/'],
            'nfce_csosn'=>['nullable','regex:/^[0-9]{3}$/'],
            'icms_csosn'=>['nullable','regex:/^[0-9]{3}$/'],
            'icms_cst'=>['nullable','regex:/^[0-9]{2}$/'],
            'pis_cst'=>['nullable','regex:/^[0-9]{2}$/'],
            'cofins_cst'=>['nullable','regex:/^[0-9]{2}$/'],
            'ipi_cst'=>['nullable','regex:/^[0-9]{2}$/'],
            'iss_exigibility'=>['nullable','string','max:2'],
            'notes'=>['nullable','string','max:3000'],
            'tax_config'=>['nullable','array'],
        ];
        foreach(self::RATE_FIELDS as $field) {
            $fields['tax_config.'.$field]=['nullable','numeric','min:0','max:100'];
        }
        foreach(self::TEXT_FIELDS as $field=>$length) {
            $fields['tax_config.'.$field]=['nullable','string','max:'.$length];
        }
        $data=$request->validate($fields);
        $data['is_active']=$request->boolean('is_active');
        $data['is_default']=$request->boolean('is_default');
        if($data['is_default'] && !$data['is_active']) {
            throw ValidationException::withMessages([
                'is_default'=>'O grupo padrão também deve estar ativo.',
            ]);
        }
        $data['tax_config']=array_filter($data['tax_config'] ?? [],static fn($v)=>$v!==null && $v!=='');
        return $data;
    }
}
