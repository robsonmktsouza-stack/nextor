<?php
namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller {
    public function index(Request $r) {
        $term=trim((string)$r->query('search',''));
        $requestedPerPage=$r->integer('per_page');
        if(in_array($requestedPerPage,[10,25,50,100],true)) {
            $r->session()->put('table_per_page',$requestedPerPage);
        }
        $perPage=(int)$r->session()->get('table_per_page',25);
        if(!in_array($perPage,[10,25,50,100],true)) $perPage=25;
        $products=Product::query()
            ->when($term,fn($q)=>$q->where(fn($t)=>$t
                ->where('name','like',"%{$term}%")
                ->orWhere('sku','like',"%{$term}%")
                ->orWhere('ncm','like',"%{$term}%")
                ->orWhere('ean_gtin','like',"%{$term}%")))
            ->orderBy('name')->paginate($perPage)->withQueryString();
        return view('products.index', compact('products','term'));
    }

    public function create() {
        return view('products.form',[
            'product'=>new Product([
                'unit'=>'UN','usage_type'=>'resale','control_stock'=>true,'is_active'=>true,
                'origin'=>'0','ignore_taxes_mode'=>'none','different_tax_unit'=>false,
                'cost_price'=>0,'sale_price'=>0,'stock_quantity'=>0,'minimum_stock'=>0,
            ]),
            'editing'=>false,
        ]);
    }

    public function edit(Product $product) {
        return view('products.form',compact('product')+['editing'=>true]);
    }

    private function rules(?Product $product=null): array {
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
            'stock_quantity'=>['nullable','numeric','min:-9999999999','max:9999999999','decimal:0,3'],
            'minimum_stock'=>['required','numeric','min:0','max:9999999999','decimal:0,3'],
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
        $requestedStock=(float)($data['stock_quantity'] ?? ($product?->stock_quantity ?? 0));
        unset($data['stock_quantity'],$data['image']);

        $data['control_stock']=$request->boolean('control_stock');
        $data['different_tax_unit']=$request->boolean('different_tax_unit');
        if(!$data['different_tax_unit']) $data['tax_unit']=null;
        $data['is_active']=$request->boolean('is_active',true);
        $data['sku']=trim((string)($data['sku'] ?? ''));
        if($data['sku']==='') $data['sku']=$product?->sku ?: $this->generateSku();

        $isNew=$product===null;

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
