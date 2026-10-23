<?php
namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuickCatalogController extends Controller
{
    /** Never silently create a name just because a search returned no results. */
    public function search(Request $request,string $kind): JsonResponse
    {
        abort_unless(in_array($kind,['products','customers','suppliers'],true),404);
        $query=trim((string)$request->query('q',''));
        $query=mb_substr($query,0,100);
        if($kind==='products'){
            $result=Product::query()->where('is_active',true)
                ->when($query!=='' ,fn($q)=>$q->where(fn($w)=>$w->where('name','like',"%{$query}%")
                    ->orWhere('sku','like',"%{$query}%")->orWhere('ean_gtin','like',"%{$query}%")))
                ->orderBy('name')->limit(25)->get();
            return response()->json(['items'=>$result->map(fn($p)=>$this->productData($p))->values()]);
        }
        $result=Customer::query()->where($kind==='suppliers'?'is_supplier':'is_customer',true)
            ->when($query!=='',fn($q)=>$q->where(fn($w)=>$w->where('name','like',"%{$query}%")
                ->orWhere('document','like',"%{$query}%")->orWhere('trade_name','like',"%{$query}%")))
            ->orderBy('name')->limit(25)->get();
        return response()->json(['items'=>$result->map(fn($c)=>$this->customerData($c))->values()]);
    }

    public function store(Request $request,string $kind): JsonResponse
    {
        abort_unless(in_array($kind,['products','customers','suppliers'],true),404);
        if($kind==='products'){
            $this->allowCreate($request,'products');
            $data=$request->validate([
                'name'=>['required','string','min:2','max:190'],
                'sku'=>['nullable','string','max:80','unique:products,sku'],
                'unit'=>['required','string','max:12'],
                'usage_type'=>['required',Rule::in(['resale','consumption','raw_material','fixed_asset','packaging','other'])],
                'cost_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
                'sale_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
                'ean_gtin'=>['nullable','string','max:32'],
                'ncm'=>['nullable','regex:/^\d{8}$/'],
                'origin'=>['required',Rule::in(['0','1','2','3','4','5','6','7','8'])],
                'control_stock'=>['required','boolean'],
            ]);
            $item=DB::transaction(function()use($data){
                if(blank($data['sku']??null)){
                    // Unique while concurrent inserts; reuse the same SKU style as Products.
                    $next=(int)Product::query()->max('id')+1;
                    do{$sku='PROD-'.str_pad((string)$next++,6,'0',STR_PAD_LEFT);}
                    while(Product::query()->where('sku',$sku)->exists());
                    $data['sku']=$sku;
                }
                return Product::query()->create(array_merge([
                    'stock_quantity'=>0,'minimum_stock'=>0,'is_active'=>true,
                    'ignore_taxes_mode'=>'none','tax_defaults'=>AppSetting::groupValues('tax',[]),
                ],$data));
            },3);
            return response()->json(['item'=>$this->productData($item),'message'=>'Produto cadastrado.'],201);
        }
        $this->allowCreate($request,'customers');
        $data=$request->validate([
            'name'=>['required','string','min:2','max:190'],
            'document'=>['nullable','string','max:20'],
            'email'=>['nullable','email','max:255'],
            'phone'=>['nullable','string','max:25'],
            'state'=>['nullable','string','size:2'],
            'city'=>['nullable','string','max:120'],
            'city_ibge_code'=>['nullable','string','max:10'],
            'address'=>['nullable','string','max:190'],
            'address_number'=>['nullable','string','max:30'],
            'state_registration'=>['nullable','string','max:40'],
            'ie_indicator'=>['required',Rule::in(['contributor','exempt','non_contributor'])],
        ]);
        $document=preg_replace('/\D/','',(string)($data['document']??''));
        if($document!==''){
            $exists=Customer::query()->whereNotNull('document')->get(['id','document'])
                ->contains(fn($c)=>preg_replace('/\D/','',(string)$c->document)===$document);
            if($exists)throw ValidationException::withMessages([
                'document'=>'Este CPF/CNPJ já está cadastrado. Pesquise e selecione o cadastro existente.',
            ]);
            $data['document']=$document;
        }
        $item=Customer::query()->create(array_merge([
            'country_code'=>'1058','country_name'=>'BRASIL',
            'is_supplier'=>$kind==='suppliers',
            'is_customer'=>$kind==='customers',
            'is_carrier'=>false,'final_consumer'=>false,
        ],$data));
        return response()->json(['item'=>$this->customerData($item),'message'=>'Cadastro salvo.'],201);
    }

    private function allowCreate(Request $request,string $permission): void
    {
        // Viewing a sale or invoice is not permission to create the central catalog.
        abort_unless($request->user()?->canAccess($permission),403,'Sem permissão para criar cadastros.');
    }

    private function productData(Product $p): array
    {
        return [
            'id'=>$p->id,'name'=>$p->name,'sku'=>$p->sku,'unit'=>$p->unit,
            'sale_price'=>(float)$p->sale_price,'cost_price'=>(float)$p->cost_price,
            'stock_quantity'=>(float)$p->stock_quantity,'control_stock'=>(bool)$p->control_stock,
            'origin'=>$p->origin,'ean_gtin'=>$p->ean_gtin,
            'ncm'=>$p->ncm,'cest'=>$p->cest,'tax_unit'=>$p->tax_unit,
            'net_weight'=>$p->net_weight,'gross_weight'=>$p->gross_weight,
            'ipi_exception'=>$p->ipi_exception,'fiscal_benefit_code'=>$p->fiscal_benefit_code,
            'nfe_notes'=>$p->nfe_notes,'tax_defaults'=>$p->tax_defaults??[],
        ];
    }

    private function customerData(Customer $c): array
    {
        return [
            'id'=>$c->id,'name'=>$c->name,'document'=>$c->document,
            'foreign_id'=>$c->foreign_id,'final_consumer'=>(bool)$c->final_consumer,
            'state_registration'=>$c->state_registration,'ie_indicator'=>$c->ie_indicator,
            'state'=>$c->state,'city'=>$c->city,'city_ibge_code'=>$c->city_ibge_code,
            'address'=>$c->address,'address_number'=>$c->address_number,
            'district'=>$c->district,'zip_code'=>$c->zip_code,
            'country_code'=>$c->country_code,'country_name'=>$c->country_name,
        ];
    }
}
