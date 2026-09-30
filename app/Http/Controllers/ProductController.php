<?php
namespace App\Http\Controllers;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
class ProductController extends Controller {
    public function index(Request $r) {
        $term=trim((string)$r->query('search',''));
        $products=Product::query()->when($term,fn($q)=>$q->where(fn($t)=>$t->where('name','like',"%{$term}%")->orWhere('sku','like',"%{$term}%")))
            ->orderBy('name')->paginate(12)->withQueryString();
        return view('products.index', compact('products','term'));
    }
    private function rules(?Product $product=null): array {
        return [
            'sku'=>['required','string','max:80', Rule::unique('products','sku')->ignore($product?->id)],
            'name'=>['required','string','max:190'], 'description'=>['nullable','string','max:5000'],
            'category'=>['nullable','string','max:100'], 'unit'=>['required','string','max:12'],
            'cost_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'sale_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'minimum_stock'=>['required','numeric','min:0','max:9999999999','decimal:0,3'],
            'is_active'=>['sometimes','boolean'],
        ];
    }
    public function store(Request $request) {
        $data=$request->validate($this->rules());
        $data['is_active']=$request->boolean('is_active',true);
        Product::create($data);
        return redirect()->route('products.index')->with('success','Produto cadastrado. Registre a entrada inicial no Estoque.');
    }
    public function update(Request $request, Product $product) {
        $data=$request->validate($this->rules($product));
        $data['is_active']=$request->boolean('is_active');
        $product->update($data);
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
                $copy->save();
                $count++;
            });
        });
        return redirect()->route('products.index')->with('success',"{$count} produto(s) duplicado(s). O estoque das cópias inicia zerado.");
    }

    public function bulkDelete(Request $request) {
        $ids=$request->validate(['ids'=>['required','array','min:1'],'ids.*'=>['integer','exists:products,id']])['ids'];
        $deleted=0;$blocked=0;
        DB::transaction(function() use ($ids,&$deleted,&$blocked) {
            Product::whereIn('id',$ids)->get()->each(function(Product $product) use (&$deleted,&$blocked) {
                if ((float)$product->stock_quantity !== 0.0 || $product->movements()->exists()) { $blocked++; return; }
                $product->delete(); $deleted++;
            });
        });
        $message="{$deleted} produto(s) excluído(s).";
        if($blocked) $message.=" {$blocked} não foram excluídos porque possuem estoque ou histórico de movimentação.";
        return redirect()->route('products.index')->with('success',$message);
    }
}
