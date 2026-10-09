<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Service;
use App\Models\CompanySetting;
use App\Models\FiscalTaxGroup;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $term=trim((string)$request->query('search',''));
        $perPage=AppSetting::tablePerPage($request);

        $services=Service::query()
            ->when($term,fn($q)=>$q->where(fn($t)=>$t
                ->where('name','like',"%{$term}%")
                ->orWhere('keywords','like',"%{$term}%")
                ->orWhere('cnae','like',"%{$term}%")
                ->orWhere('service_list_item','like',"%{$term}%")
                ->orWhere('municipal_tax_code','like',"%{$term}%")
                ->orWhere('national_tax_code','like',"%{$term}%")))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return view('services.index',compact('services','term'));
    }

    public function create()
    {
        $catalog=AppSetting::groupValues('catalog',[
            'new_services_active'=>true,
        ]);

        $taxDefaults=AppSetting::groupValues('tax',[]);

        return view('services.form',[
            'service'=>new Service([
                'sale_price'=>0,
                'tax_group'=>$taxDefaults['tax_classification_code'] ?? null,
                'tax_defaults'=>$taxDefaults,
                'is_active'=>(bool)$catalog['new_services_active'],
            ]),
            'editing'=>false,
            'fiscalTaxGroups'=>$this->serviceTaxGroups(),
        ]);
    }

    public function edit(Service $service)
    {
        return view('services.form',compact('service')+[
            'editing'=>true,
            'fiscalTaxGroups'=>$this->serviceTaxGroups(),
        ]);
    }


    private function serviceTaxGroups(): \Illuminate\Database\Eloquent\Collection
    {
        $crt=(string)(CompanySetting::current()->crt ?? '');
        return FiscalTaxGroup::query()
            ->where('kind','services')
            ->where(fn($query)=>$query->whereNull('target_crt')->orWhere('target_crt',$crt))
            ->orderByDesc('is_default')->orderBy('name')->get();
    }

    private function rules(): array
    {
        return [
            'name'=>['required','string','max:190'],
            'sale_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'keywords'=>['nullable','string','max:500'],
            'notes'=>['nullable','string','max:5000'],
            'service_list_item'=>['nullable','string','max:20'],
            'cnae'=>['nullable','string','max:12'],
            'municipal_tax_code'=>['nullable','string','max:40'],
            'national_tax_code'=>['nullable','string','max:40'],
            'nbs'=>['nullable','string','max:20'],
            'tax_group'=>['nullable','string','max:120'],
            'fiscal_tax_group_id'=>['nullable','integer',Rule::exists('fiscal_tax_groups','id')
                ->where('kind','services')
                ->where(fn($query)=>$query->whereNull('target_crt')
                    ->orWhere('target_crt',(string)(CompanySetting::current()->crt ?? '')))],
            'is_active'=>['nullable','boolean'],
        ];
    }

    private function persist(Request $request, ?Service $service=null): Service
    {
        $data=$request->validate($this->rules());
        $data['is_active']=$request->boolean('is_active',true);

        $groupId=(int)($data['fiscal_tax_group_id'] ?? 0);
        if ($groupId > 0) {
            $group=FiscalTaxGroup::query()->where('kind','services')->findOrFail($groupId);
            if (!$group->is_active) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'fiscal_tax_group_id'=>'Selecione um grupo tributário ativo.',
                ]);
            }
            $config=is_array($group->tax_config) ? $group->tax_config : [];
            $national=(string)($config['national_tax_code'] ?? '');
            $listItem=(string)($config['service_list_item'] ?? '');
            if (!preg_match('/^[0-9]{6}$/',$national)
                || $listItem !== \App\Support\FiscalServicePresetCatalog::item($national)
                || (string)$group->iss_exigibility === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'fiscal_tax_group_id'=>'Este grupo de serviços está incompleto.',
                ]);
            }
            // O grupo prevalece sobre os campos anteriores: ao trocar de
            // atividade, não copiar impostos nem códigos do grupo anterior.
            $data['national_tax_code']=$national;
            $data['service_list_item']=$listItem;
            $data['tax_defaults']=array_replace($config, [
                'iss_exigibility'=>(string)$group->iss_exigibility,
                'fiscal_group_id'=>$group->id,
                'fiscal_group_revision'=>$group->revision,
            ]);
        } else {
            // Sem grupo: não manter dados de um grupo antigo.
            $data['tax_defaults']=[];
        }

        if($service) {
            $service->update($data);
            return $service->refresh();
        }

        return Service::create($data);
    }

    public function store(Request $request)
    {
        $this->persist($request);
        return redirect()->route('services.index')->with('success','Serviço cadastrado.');
    }

    public function update(Request $request, Service $service)
    {
        $this->persist($request,$service);
        return redirect()->route('services.index')->with('success','Serviço atualizado.');
    }

    public function bulkDuplicate(Request $request)
    {
        $ids=$request->validate([
            'ids'=>['required','array','min:1'],
            'ids.*'=>['integer','exists:services,id'],
        ])['ids'];

        $count=0;
        DB::transaction(function() use ($ids,&$count) {
            Service::whereIn('id',$ids)->orderBy('id')->get()->each(function(Service $service) use (&$count) {
                $copy=$service->replicate();
                $copy->name=$service->name.' (cópia)';
                $copy->save();
                $count++;
            });
        });

        return redirect()->route('services.index')->with('success',"{$count} serviço(s) duplicado(s).");
    }

    public function bulkStatus(Request $request)
    {
        $data=$request->validate([
            'ids'=>['required','array','min:1'],
            'ids.*'=>['integer','exists:services,id'],
            'status'=>['required','in:active,inactive'],
        ]);

        $active=$data['status']==='active';
        $count=Service::whereIn('id',$data['ids'])->update(['is_active'=>$active]);

        return redirect()->route('services.index')->with('success',"{$count} serviço(s) ".($active?'ativado(s).':'inativado(s).'));
    }

    public function bulkDelete(Request $request)
    {
        $ids=$request->validate([
            'ids'=>['required','array','min:1'],
            'ids.*'=>['integer','exists:services,id'],
        ])['ids'];

        $count=Service::whereIn('id',$ids)->delete();

        return redirect()->route('services.index')->with('success',"{$count} serviço(s) excluído(s).");
    }
}
