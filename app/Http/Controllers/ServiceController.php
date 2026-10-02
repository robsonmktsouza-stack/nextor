<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Service;
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

        return view('services.form',[
            'service'=>new Service([
                'sale_price'=>0,
                'is_active'=>(bool)$catalog['new_services_active'],
            ]),
            'editing'=>false,
        ]);
    }

    public function edit(Service $service)
    {
        return view('services.form',compact('service')+['editing'=>true]);
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
            'is_active'=>['nullable','boolean'],
        ];
    }

    private function persist(Request $request, ?Service $service=null): Service
    {
        $data=$request->validate($this->rules());
        $data['is_active']=$request->boolean('is_active',true);

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
