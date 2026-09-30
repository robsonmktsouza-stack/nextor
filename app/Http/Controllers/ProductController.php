<?php
namespace App\Http\Controllers;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
}
