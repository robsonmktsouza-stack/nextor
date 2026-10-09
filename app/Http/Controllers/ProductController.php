<?php
namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Product;
use App\Models\FiscalTaxGroup;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller {
    public function index(Request $r) {
        $term=trim((string)$r->query('search',''));
        $perPage=AppSetting::tablePerPage($r);
        $products=Product::query()
            ->when($term,fn($q)=>$q->where(fn($t)=>$t
                ->where('name','like',"%{$term}%")
                ->orWhere('sku','like',"%{$term}%")
                ->orWhere('ncm','like',"%{$term}%")
                ->orWhere('ean_gtin','like',"%{$term}%")))
            ->orderBy('name')->paginate($perPage)->withQueryString();
        $stockDecimalPlaces=max(0,min(3,(int)AppSetting::value('inventory','stock_decimal_places',3)));
        $minimumStockAlerts=(bool)AppSetting::value('inventory','minimum_stock_alerts',true);
        return view('products.index', compact('products','term','stockDecimalPlaces','minimumStockAlerts'));
    }

    public function create() {
        $catalog=AppSetting::groupValues('catalog',[
            'product_unit'=>'UN','product_usage_type'=>'resale','product_control_stock'=>true,
            'product_minimum_stock'=>'0.000','new_products_active'=>true,
        ]);

        $taxDefaults=AppSetting::groupValues('tax',[]);

        return view('products.form',[
            'product'=>new Product([
                'unit'=>$catalog['product_unit'] ?: 'UN',
                'usage_type'=>$catalog['product_usage_type'] ?: 'resale',
                'control_stock'=>(bool)$catalog['product_control_stock'],
                'is_active'=>(bool)$catalog['new_products_active'],
                'origin'=>$taxDefaults['icms_origin_default'] ?? '0',
                'tax_group'=>$taxDefaults['tax_classification_code'] ?? null,
                'tax_defaults'=>$taxDefaults,
                'ignore_taxes_mode'=>'none','different_tax_unit'=>false,
                'cost_price'=>0,'sale_price'=>0,'stock_quantity'=>0,
                'minimum_stock'=>$catalog['product_minimum_stock'] ?? 0,
            ]),
            'editing'=>false,
            'stockDecimalPlaces'=>max(0,min(3,(int)AppSetting::value('inventory','stock_decimal_places',3))),
            'fiscalTaxGroups'=>FiscalTaxGroup::query()->where('kind','products')->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function edit(Product $product) {
        return view('products.form',compact('product')+[
            'editing'=>true,
            'stockDecimalPlaces'=>max(0,min(3,(int)AppSetting::value('inventory','stock_decimal_places',3))),
            'fiscalTaxGroups'=>FiscalTaxGroup::query()->where('kind','products')->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    private function rules(?Product $product=null): array {
        $stockPlaces=max(0,min(3,(int)AppSetting::value('inventory','stock_decimal_places',3)));
        $stockRule=$stockPlaces===0 ? 'integer' : 'decimal:0,'.$stockPlaces;

        return [
            'sku'=>['nullable','string','max:80', Rule::unique('products','sku')->ignore($product?->id)],
            'name'=>['required','string','max:190'],
            'description'=>['nullable','string','max:5000'],
            'category'=>['nullable','string','max:100'],
            'keywords'=>['nullable','string','max:500'],
            'usage_type'=>['required','in:resale,consumption,raw_material,fixed_asset,packaging,other'],
            'unit'=>['required','string','max:12'],
            'cost_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'sale_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'stock_quantity'=>['nullable','numeric','min:-9999999999','max:9999999999',$stockRule],
            'minimum_stock'=>['required','numeric','min:0','max:9999999999',$stockRule],
            'control_stock'=>['nullable','boolean'],
            'is_active'=>['nullable','boolean'],

            'origin'=>['required','string','max:2'],
            'ean_gtin'=>['nullable','string','max:32'],
            'net_weight'=>['nullable','numeric','min:0','max:99999999','decimal:0,3'],
            'gross_weight'=>['nullable','numeric','min:0','max:99999999','decimal:0,3'],
            'ncm'=>['nullable','string','max:10'],
            'ipi_exception'=>['nullable','string','max:20'],
            'cest'=>['nullable','string','max:10'],
            'fiscal_benefit_code'=>['nullable','string','max:40'],
            'different_tax_unit'=>['nullable','boolean'],
            'tax_unit'=>['nullable','string','max:12'],
            'ignore_taxes_mode'=>['required','in:none,purchase,sale,both'],
            'nfe_notes'=>['nullable','string','max:5000'],
            'tax_group'=>['nullable','string','max:120'],
            'fiscal_tax_group_id'=>['nullable','integer',Rule::exists('fiscal_tax_groups','id')->where('kind','products')],
            'tax_defaults'=>['nullable','array'],
            'tax_defaults.cfop_outbound_internal'=>['nullable','string','max:10'],
            'tax_defaults.cfop_outbound_interstate'=>['nullable','string','max:10'],
            'tax_defaults.cfop_inbound_internal'=>['nullable','string','max:10'],
            'tax_defaults.cfop_inbound_interstate'=>['nullable','string','max:10'],
            'tax_defaults.icms_csosn'=>['nullable','string','max:4'],
            'tax_defaults.icms_cst'=>['nullable','string','max:4'],
            'tax_defaults.icms_csosn_export'=>['nullable','string','max:4'],
            'tax_defaults.icms_csosn_inbound'=>['nullable','string','max:4'],
            'tax_defaults.icms_cst_inbound'=>['nullable','string','max:4'],
            'tax_defaults.icms_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.base_reduction_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.simple_credit_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.mod_bc'=>['nullable','string','max:4'],
            'tax_defaults.mod_bc_st'=>['nullable','string','max:4'],
            'tax_defaults.st_retained_amount_scope'=>['nullable',\Illuminate\Validation\Rule::in(['unit','line'])],
            'tax_defaults.icms_st_retained_base'=>['nullable','numeric','min:0'],
            'tax_defaults.icms_st_retained_value'=>['nullable','numeric','min:0'],
            'tax_defaults.st_retained_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.icms_effective_base'=>['nullable','numeric','min:0'],
            'tax_defaults.icms_effective_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.icms_effective_value'=>['nullable','numeric','min:0'],
            'tax_defaults.icms_st_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.mva_rate'=>['nullable','numeric','min:0','max:1000'],
            'tax_defaults.pis_cst'=>['nullable','string','max:4'],
            'tax_defaults.pis_calc_type'=>['nullable',\Illuminate\Validation\Rule::in(['none','percentage','quantity'])],
            'tax_defaults.pis_quantity_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.pis_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.pis_cst_inbound'=>['nullable','string','max:4'],
            'tax_defaults.cofins_cst'=>['nullable','string','max:4'],
            'tax_defaults.cofins_calc_type'=>['nullable',\Illuminate\Validation\Rule::in(['none','percentage','quantity'])],
            'tax_defaults.cofins_quantity_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.cofins_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.cofins_cst_inbound'=>['nullable','string','max:4'],
            'tax_defaults.ipi_cst'=>['nullable','string','max:4'],
            'tax_defaults.ipi_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.ipi_cst_inbound'=>['nullable','string','max:4'],
            'tax_defaults.ipi_enq'=>['nullable','string','max:10'],
            'tax_defaults.iss_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.service_list_code'=>['nullable','string','max:20'],
            'tax_defaults.interstate_icms_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.internal_icms_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.fcp_interstate_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.tax_quantity_factor'=>['nullable','numeric','min:0'],
            'tax_defaults.petroleum_derived'=>['nullable','boolean'],
            'tax_defaults.anp_code'=>['nullable','string','max:20'],
            'tax_defaults.anp_description'=>['nullable','string','max:190'],
            'tax_defaults.glp_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.gnn_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.gni_rate'=>['nullable','numeric','min:0','max:100'],
            'tax_defaults.starting_value'=>['nullable','numeric','min:0'],
            'tax_defaults.ibs_cst'=>['nullable','string','max:10'],
            'tax_defaults.cbs_cst'=>['nullable','string','max:10'],
            'tax_defaults.tax_classification_code'=>['nullable','string','max:40'],

            'image'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
            'integration_reference'=>['nullable','string','max:120'],
            'integration_sku'=>['nullable','string','max:120'],
        ];
    }

    private function generateSku(): string {
        $n=(int)Product::max('id')+1;
        do {
            $sku='PROD-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);
            $n++;
        } while(Product::where('sku',$sku)->exists());
        return $sku;
    }

    private function persist(Request $request, ?Product $product=null): Product {
        $data=$request->validate($this->rules($product));

        // Salvar NCM como oito dígitos, mesmo quando o usuário o informar
        // com a máscara visual 6913.90.00. Não inventar NCM incompleto.
        $enteredNcm = trim((string) ($data['ncm'] ?? ''));
        if ($enteredNcm !== '' && preg_match('/^[0-9.\\s-]+$/', $enteredNcm) === 1) {
            $ncmDigits = preg_replace('/\\D/', '', $enteredNcm);
            if (strlen($ncmDigits) === 8) {
                $data['ncm'] = $ncmDigits;
            }
        }

        $requestedStock=(float)($data['stock_quantity'] ?? ($product?->stock_quantity ?? 0));
        $submittedTaxDefaults=$data['tax_defaults'] ?? [];
        unset($data['stock_quantity'],$data['image'],$data['tax_defaults']);

        $data['control_stock']=$request->boolean('control_stock');
        $data['different_tax_unit']=$request->boolean('different_tax_unit');
        if(!$data['different_tax_unit']) $data['tax_unit']=null;
        $data['is_active']=$request->boolean('is_active',true);
        $data['sku']=trim((string)($data['sku'] ?? ''));
        if($data['sku']==='') $data['sku']=$product?->sku ?: $this->generateSku();

        $isNew=$product===null;
        $baseTaxDefaults=$isNew
            ? AppSetting::groupValues('tax',[])
            : ($product?->tax_defaults ?? []);
        $data['tax_defaults']=array_replace($baseTaxDefaults,$submittedTaxDefaults);
        $data['tax_defaults']['petroleum_derived']=$request->boolean('tax_defaults.petroleum_derived');

        return DB::transaction(function() use ($request,$product,$data,$requestedStock,$isNew) {
            $oldImage=$product?->image_path;

            if($request->hasFile('image')) {
                $data['image_path']=$request->file('image')->store('products','public');
            }

            if($product) {
                $previous=(float)$product->stock_quantity;
                $product->update($data);
            } else {
                $previous=0.0;
                $data['stock_quantity']=0;
                $product=Product::create($data);
            }

            if(abs($requestedStock-$previous)>0.0004) {
                StockMovement::create([
                    'product_id'=>$product->id,
                    'user_id'=>auth()->id(),
                    'sale_id'=>null,
                    'type'=>'adjustment',
                    'quantity_delta'=>$requestedStock-$previous,
                    'previous_quantity'=>$previous,
                    'new_quantity'=>$requestedStock,
                    'reason'=>$isNew
                        ? 'Estoque inicial pelo cadastro do produto'
                        : 'Ajuste pelo cadastro do produto',
                ]);
                $product->updateQuietly(['stock_quantity'=>$requestedStock]);
            }

            if($request->hasFile('image') && $oldImage && $oldImage!==$product->image_path) {
                Storage::disk('public')->delete($oldImage);
            }

            return $product->refresh();
        });
    }

    public function store(Request $request) {
        $this->persist($request);
        return redirect()->route('products.index')->with('success','Produto cadastrado.');
    }

    public function update(Request $request, Product $product) {
        $this->persist($request,$product);
        return redirect()->route('products.index')->with('success','Produto atualizado.');
    }

    public function bulkDuplicate(Request $request) {
        $ids=$request->validate(['ids'=>['required','array','min:1'],'ids.*'=>['integer','exists:products,id']])['ids'];
        $count=0;
        DB::transaction(function() use ($ids,&$count) {
            Product::whereIn('id',$ids)->orderBy('id')->get()->each(function(Product $product) use (&$count) {
                $base=$product->sku.'-COPIA';
                $sku=$base;
                $n=2;
                while(Product::where('sku',$sku)->exists()) $sku=$base.'-'.$n++;
                $copy=$product->replicate();
                $copy->sku=$sku;
                $copy->name=$product->name.' (cópia)';
                $copy->stock_quantity=0;
                $copy->image_path=null;
                $copy->save();
                $count++;
            });
        });
        return redirect()->route('products.index')->with('success',"{$count} produto(s) duplicado(s). O estoque das cópias inicia zerado.");
    }

    public function bulkStatus(Request $request) {
        $data=$request->validate([
            'ids'=>['required','array','min:1'],
            'ids.*'=>['integer','exists:products,id'],
            'status'=>['required','in:active,inactive'],
        ]);
        $active=$data['status']==='active';
        $count=Product::whereIn('id',$data['ids'])->update(['is_active'=>$active]);
        return redirect()->route('products.index')->with('success',"{$count} produto(s) ".($active?'ativado(s).':'inativado(s).'));
    }

    public function bulkDelete(Request $request) {
        $ids=$request->validate(['ids'=>['required','array','min:1'],'ids.*'=>['integer','exists:products,id']])['ids'];
        $deleted=0;$blocked=0;
        DB::transaction(function() use ($ids,&$deleted,&$blocked) {
            Product::whereIn('id',$ids)->get()->each(function(Product $product) use (&$deleted,&$blocked) {
                if ((float)$product->stock_quantity !== 0.0 || $product->movements()->exists()) { $blocked++; return; }
                if($product->image_path) Storage::disk('public')->delete($product->image_path);
                $product->delete(); $deleted++;
            });
        });
        $message="{$deleted} produto(s) excluído(s).";
        if($blocked) $message.=" {$blocked} não foram excluídos porque possuem estoque ou histórico de movimentação.";
        return redirect()->route('products.index')->with('success',$message);
    }
}
