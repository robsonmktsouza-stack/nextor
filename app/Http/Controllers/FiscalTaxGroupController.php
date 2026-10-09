<?php

namespace App\Http\Controllers;

use App\Models\FiscalTaxGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FiscalTaxGroupController extends Controller
{
    private const RATE_FIELDS = [
        'icms_rate','base_reduction_rate','credit_rate','icms_st_rate','mva_rate','fcp_deferral_rate',
        'pis_rate','pis_st_rate','pis_quantity_rate',
        'cofins_rate','cofins_st_rate','cofins_quantity_rate',
        'ipi_rate','iss_rate','is_rate','biodiesel_mix_rate','fuel_origin_rate',
        'cbs_rate','cbs_deferral_rate','cbs_reduction_rate',
        'ibs_uf_rate','ibs_uf_deferral_rate','ibs_uf_reduction_rate',
        'ibs_municipal_rate','ibs_municipal_deferral_rate','ibs_municipal_reduction_rate',
    ];

    private const TEXT_FIELDS = [
        'mod_bc' => 1,
        'service_list_item' => 5,
        'national_tax_code' => 6,
        'iss_calculation' => 25,
        'iss_city_ibge' => 7,
        'iss_legal_reference' => 190,
        'ibs_cbs_cst' => 3,
        'ibs_cbs_class' => 6,
        'is_cst' => 3,
        'is_class' => 6,
        'nfce_note' => 255,
        'fiscal_benefit_code' => 20,
        'cfop_interstate' => 4,
        'anp_code' => 12,
        'anp_description' => 190,
        'fuel_origin_indicator' => 2,
        'fuel_origin_uf' => 2,
    ];

    private const CALC_FIELDS = [
        'pis_calc_type','pis_st_calc_type',
        'cofins_calc_type','cofins_st_calc_type',
    ];

    private const UF = [
        'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS',
        'MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO',
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
                    ->where('id','!=',$group->id)
                    ->update(['is_default'=>false]);
            }
            if ($group->kind === 'services' && $data['kind'] === 'services') {
                // Campos do formulário de produtos ficam ocultos na edição
                // de serviços. Não apagar configurações fiscais existentes.
                $merged = array_replace($group->tax_config ?? [], $data['tax_config'] ?? []);
                $data['tax_config'] = $merged;
            }
            $data['revision']=$group->revision+1;
            $group->update($data);
        });

        return redirect()->route('fiscal.tax-groups.index')
            ->with('success','Grupo de tributação atualizado. Documentos já assinados não são alterados.');
    }

    private function validated(Request $request): array
    {
        $rules=[
            'name'=>['required','string','max:160'],
            'kind'=>['required',Rule::in(['products','services'])],
            'cfop_pattern'=>['nullable','regex:/^(?:[567][0-9]{3}|x[0-9]{3})$/'],
            'nfce_csosn'=>['nullable','regex:/^[0-9]{3}$/'],
            'icms_csosn'=>['nullable','regex:/^[0-9]{3}$/'],
            'icms_cst'=>['nullable','regex:/^[0-9]{2}$/'],
            'pis_cst'=>['nullable','regex:/^[0-9]{2}$/'],
            'cofins_cst'=>['nullable','regex:/^[0-9]{2}$/'],
            'ipi_cst'=>['nullable','regex:/^[0-9]{2}$/'],
            'iss_exigibility'=>['nullable',Rule::in(['1','2','3','4','5','6','7'])],
            'notes'=>['nullable','string','max:3000'],
            'tax_config'=>['nullable','array'],
            'tax_config.municipal_variations'=>['nullable','array','max:100'],
            'tax_config.municipal_variations.*'=>['array'],
            'tax_config.municipal_variations.*.city_ibge'=>['required','regex:/^[0-9]{7}$/'],
            'tax_config.municipal_variations.*.rate'=>['nullable','numeric','min:0','max:100'],
            'tax_config.municipal_variations.*.deferral_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_config.municipal_variations.*.reduction_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_config.state_variations'=>['nullable','array','max:27'],
            'tax_config.state_variations.*'=>['array'],
            'tax_config.state_variations.*.uf'=>['required',Rule::in(self::UF)],
            'tax_config.state_variations.*.cfop'=>['nullable','regex:/^[567][0-9]{3}$/'],
            'tax_config.state_variations.*.cbs_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_config.state_variations.*.ibs_uf_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_config.state_variations.*.ibs_uf_deferral_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_config.state_variations.*.ibs_uf_reduction_rate'=>['nullable','numeric','min:0','max:100'],
        ];
        foreach (self::RATE_FIELDS as $key) {
            $rules['tax_config.'.$key]=['nullable','numeric','min:0','max:100'];
        }
        foreach (self::TEXT_FIELDS as $key=>$max) {
            $rules['tax_config.'.$key]=['nullable','string','max:'.$max];
        }
        foreach (self::CALC_FIELDS as $key) {
            $rules['tax_config.'.$key]=['nullable',Rule::in(['none','percentage','quantity'])];
        }
        $rules['tax_config.mod_bc']=['nullable',Rule::in(['0','1','2','3'])];
        $rules['tax_config.force_interstate_cfop']=['nullable','boolean'];
        $rules['tax_config.iss_incentive']=['nullable','boolean'];
        $rules['tax_config.fuel_origin_uf']=['nullable',Rule::in(self::UF)];
        $rules['tax_config.cfop_interstate']=['nullable','regex:/^6[0-9]{3}$/'];
        $rules['tax_config.fiscal_benefit_code']=['nullable','regex:/^[a-zA-Z0-9.-]{1,20}$/'];
        $rules['tax_config.ibs_cbs_cst']=['nullable','regex:/^[0-9]{3}$/'];
        $rules['tax_config.ibs_cbs_class']=['nullable','regex:/^[0-9]{6}$/'];
        $rules['tax_config.is_cst']=['nullable','regex:/^[0-9]{3}$/'];
        $rules['tax_config.is_class']=['nullable','regex:/^[0-9]{6}$/'];
        $rules['tax_config.service_list_item']=['nullable','regex:/^[0-9]{2}\.[0-9]{2}$/'];
        $rules['tax_config.national_tax_code']=['nullable','regex:/^[0-9]{6}$/'];
        $rules['tax_config.iss_calculation']=['nullable',Rule::in(['municipal_or_simples','municipal','simples_effective','mei_fixed'])];
        $rules['tax_config.iss_city_ibge']=['nullable','regex:/^[0-9]{7}$/'];
        $rules['tax_config.anp_code']=['nullable','regex:/^[0-9]{1,12}$/'];

        $data=$request->validate($rules);
        $data['is_active']=$request->boolean('is_active');
        $data['is_default']=$request->boolean('is_default');
        if ($data['is_default'] && !$data['is_active']) {
            throw ValidationException::withMessages(['is_default'=>'O grupo padrão também deve estar ativo.']);
        }

        if (($data['kind'] ?? '') === 'services') {
            $national = (string) data_get($data, 'tax_config.national_tax_code', '');
            $item = (string) data_get($data, 'tax_config.service_list_item', '');
            if (($national !== '' || $item !== '')
                && ($national === '' || $item !== ''
                    && \App\Support\FiscalServicePresetCatalog::item($national) !== $item)) {
                throw ValidationException::withMessages([
                    'tax_config.national_tax_code'=>'Código de serviço nacional diferente do item da lista.',
                ]);
            }
            if (($data['is_active'] ?? false) && ($national === '' || $item === '')) {
                throw ValidationException::withMessages([
                    'tax_config.national_tax_code'=>'Selecione um código de serviço para ativar este grupo.',
                ]);
            }
            if (data_get($data,'tax_config.iss_rate') !== null
                && data_get($data,'tax_config.iss_rate') !== ''
                && (empty(data_get($data,'tax_config.iss_city_ibge'))
                    || empty(data_get($data,'tax_config.iss_legal_reference')))) {
                throw ValidationException::withMessages([
                    'tax_config.iss_rate'=>'Para informar a alíquota, indique o município e a lei municipal.',
                ]);
            }
        }

        $ibsCst=(string) data_get($data,'tax_config.ibs_cbs_cst','');
        $ibsClass=(string) data_get($data,'tax_config.ibs_cbs_class','');
        if ($ibsCst!=='' && $ibsClass!=='' && !str_starts_with($ibsClass,$ibsCst)) {
            throw ValidationException::withMessages([
                'tax_config.ibs_cbs_class'=>'A classificação tributária deve pertencer ao CST IBS/CBS selecionado.',
            ]);
        }

        $config=Arr::only($data['tax_config'] ?? [],
            array_merge(self::RATE_FIELDS,array_keys(self::TEXT_FIELDS),self::CALC_FIELDS,
                ['force_interstate_cfop','iss_incentive','municipal_variations','state_variations']));
        foreach (['force_interstate_cfop','iss_incentive'] as $flag) {
            $config[$flag]=$request->boolean('tax_config.'.$flag);
        }
        // A interface usa linhas dinâmicas; aceitar apenas os campos
        // explicitamente validados e rejeitar critérios duplicados.
        $variations=[
            'municipal_variations'=>['city_ibge','rate','deferral_rate','reduction_rate'],
            'state_variations'=>['uf','cfop','cbs_rate','ibs_uf_rate','ibs_uf_deferral_rate','ibs_uf_reduction_rate'],
        ];
        foreach ($variations as $field=>$keys) {
            $rows=[];
            $seen=[];
            foreach (($config[$field] ?? []) as $row) {
                $row=Arr::only($row,$keys);
                $id=$field==='state_variations' ? ($row['uf'] ?? '') : ($row['city_ibge'] ?? '');
                if (isset($seen[$id])) {
                    throw ValidationException::withMessages(['tax_config.'.$field=>'Há variações duplicadas para o mesmo destino.']);
                }
                $seen[$id]=true;
                $rows[]=array_filter($row,static fn($v)=>$v!==null && $v!=='');
            }
            $config[$field]=$rows;
        }
        if (!empty($config['force_interstate_cfop']) && empty($config['cfop_interstate'])) {
            throw ValidationException::withMessages([
                'tax_config.cfop_interstate'=>'Informe o CFOP interestadual para utilizar a opção de forçar CFOP.',
            ]);
        }

        $data['tax_config']=array_filter($config,static fn($v)=>$v!==null && $v!=='' && $v!==[]);
        return $data;
    }
}
