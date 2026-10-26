<div class="lm-native-list lm-products-list" aria-label="Produtos no celular">
  <div class="lm-list-summary"><span>{{ $products->total() }} produto(s)</span><span>Toque para editar</span></div>
  @forelse($products as $product)
    <article class="lm-list-card">
      <a class="lm-card-main" href="{{ route('products.edit',$product) }}">
        <span class="lm-card-identity"><span class="lm-list-avatar">@include('partials.icon',['name'=>'products','size'=>19])</span>
          <span class="lm-card-copy"><strong>{{ $product->name }}</strong><small>{{ $product->sku }}@if($product->category) · {{ $product->category }}@endif</small></span>
          @include('partials.icon',['name'=>'chevron','size'=>18])
        </span>
      </a>
      <div class="lm-card-facts">
        <span><small>Preço de venda</small><strong>R$ {{ number_format((float)$product->sale_price,2,',','.') }}</strong></span>
        <span><small>Estoque</small><strong class="{{ $minimumStockAlerts && (float)$product->minimum_stock>0 && (float)$product->stock_quantity <= (float)$product->minimum_stock ? 'lm-negative' : '' }}">{{ number_format((float)$product->stock_quantity,$stockDecimalPlaces,',','.') }} {{ $product->unit }}</strong></span>
      </div>
      <div class="lm-card-bottom">
        <span class="lm-state {{ $product->is_active?'lm-state-ok':'lm-state-muted' }}">{{ $product->is_active?'Ativo':'Inativo' }}</span>
        @if(auth()->user()->canAccess('stock'))
          <a href="{{ route('stock.index',['product_id'=>$product->id,'open'=>1]) }}">Movimentar estoque @include('partials.icon',['name'=>'chevron','size'=>14])</a>
        @endif
      </div>
    </article>
  @empty
    <div class="lm-empty">Nenhum produto encontrado.</div>
  @endforelse
</div>
