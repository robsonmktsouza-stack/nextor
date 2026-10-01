@extends('layouts.app')
@section('titleMeta'){{ $products->total() }} {{ $products->total() === 1 ? 'registro' : 'registros' }}@endsection
@section('title','Produtos')
@section('description','Cadastre e organize os itens comercializados, com controle de preços e quantidades.')
@section('content')
<section class="cms-card">
 <div class="grid-actionbar">
   <div class="grid-actions-left">
     <a class="grid-primary-action" href="{{ route('products.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Novo</span></a>
     <div class="grid-tool-group">
       <button class="grid-tool grid-tool-wide" type="button" data-bulk-submit="products-duplicate" disabled>@include('partials.icon',['name'=>'copy','size'=>18])<span>Duplicar</span></button>
       <button class="grid-tool grid-tool-danger" type="button" data-bulk-submit="products-delete" data-confirm="Excluir os produtos selecionados? Produtos com estoque ou movimentações serão preservados." disabled data-tooltip="Excluir selecionados">@include('partials.icon',['name'=>'trash','size'=>18])</button>
       <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
       <button class="grid-tool" type="button" data-export-table="products.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
       <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
     </div>
     <div class="bulk-actions">
       <select class="bulk-action-select" data-bulk-menu disabled aria-label="Ações em massa">
         <option value="">Ações em massa</option>
         <option value="products-active">Ativar selecionados</option>
         <option value="products-inactive">Inativar selecionados</option>
         <option value="products-duplicate">Duplicar selecionados</option>
         <option value="local:export">Exportar selecionados</option>
         <option value="local:print">Imprimir selecionados</option>
         <option value="products-delete" data-confirm="Excluir os produtos selecionados? Produtos com estoque ou movimentações serão preservados.">Excluir selecionados</option>
       </select>
       <button class="bulk-apply" type="button" data-bulk-apply disabled>Aplicar</button>
     </div>
     <span class="selection-count" data-selection-count hidden></span>
     <form id="products-duplicate" method="post" action="{{ route('products.bulk-duplicate') }}" hidden>@csrf</form>
     <form id="products-active" method="post" action="{{ route('products.bulk-status') }}" hidden>@csrf<input type="hidden" name="status" value="active"></form>
     <form id="products-inactive" method="post" action="{{ route('products.bulk-status') }}" hidden>@csrf<input type="hidden" name="status" value="inactive"></form>
     <form id="products-delete" method="post" action="{{ route('products.bulk-delete') }}" hidden>@csrf @method('DELETE')</form>
   </div>
   <div class="grid-actions-right">
     <button class="grid-filter-button" type="button" data-filter-toggle="product-filters" data-tooltip="Filtro avançado" aria-label="Filtro avançado">@include('partials.icon',['name'=>'search','size'=>18])</button>
   </div>
 </div>
 <div class="grid-filter-panel" id="product-filters" @if(!$term) hidden @endif>
   <form method="get" class="toolbar-filters"><div class="table-search-group"><input name="search" value="{{ $term }}" placeholder="Buscar por nome ou código..." aria-label="Buscar produtos"><button type="submit" class="table-search-submit" data-tooltip="Pesquisar" aria-label="Pesquisar">@include('partials.icon',['name'=>'search','size'=>17])</button></div>@if($term)<a class="btn btn-light" href="{{ route('products.index') }}">Limpar</a>@endif</form>
 </div>
 <div class="table-scroll"><table class="cms-table"><thead><tr><th class="select-cell"><input type="checkbox" data-check-all aria-label="Selecionar todos"></th><th>Código / SKU</th><th>Produto</th><th>Un.</th><th>Preço de custo</th><th>Preço de venda</th><th>Estoque</th><th>Status</th><th class="action-cell">Ações</th></tr></thead><tbody>
 @forelse($products as $product)
 <tr><td class="select-cell"><input type="checkbox" data-row-select value="{{ $product->id }}" aria-label="Selecionar {{ $product->name }}"></td><td><span class="code-tag">{{ $product->sku }}</span></td>
 <td><strong class="table-title">{{ $product->name }}</strong>@if($product->category)<small class="table-subtitle">{{ $product->category }}</small>@endif</td>
 <td>{{ $product->unit }}</td>
 <td class="nowrap">R$ {{ number_format((float)$product->cost_price,2,',','.') }}</td>
 <td class="nowrap price-strong">R$ {{ number_format((float)$product->sale_price,2,',','.') }}</td>
 <td><span class="{{ (float)$product->stock_quantity <= (float)$product->minimum_stock ? 'stock-low':'stock-normal' }}">{{ number_format((float)$product->stock_quantity,3,',','.') }}</span></td>
 <td><span class="status {{ $product->is_active?'status-ok':'status-muted' }}">{{ $product->is_active?'Ativo':'Inativo' }}</span></td>
 <td class="action-cell">
   <div class="row-actions">
     <a class="btn-icon" href="{{ route('stock.index',['product_id'=>$product->id,'open'=>1]) }}" data-tooltip="Movimentar estoque" aria-label="Movimentar estoque de {{ $product->name }}">@include('partials.icon',['name'=>'stock','size'=>16])</a>
     <form method="post" action="{{ route('products.bulk-duplicate') }}" class="row-action-form">@csrf<input type="hidden" name="ids[]" value="{{ $product->id }}"><button type="submit" class="btn-icon" data-tooltip="Duplicar produto" aria-label="Duplicar produto {{ $product->name }}">@include('partials.icon',['name'=>'copy','size'=>16])</button></form>
     <a class="btn-icon" href="{{ route('products.edit',$product) }}" data-tooltip="Editar produto" aria-label="Editar produto {{ $product->name }}">@include('partials.icon',['name'=>'edit','size'=>16])</a>
     <form method="post" action="{{ route('products.bulk-delete') }}" class="row-action-form" data-confirm-submit="Excluir este produto? Se ele tiver estoque ou histórico de movimentação, será preservado.">@csrf @method('DELETE')<input type="hidden" name="ids[]" value="{{ $product->id }}"><button type="submit" class="btn-icon row-action-danger" data-tooltip="Excluir produto" aria-label="Excluir produto {{ $product->name }}">@include('partials.icon',['name'=>'trash','size'=>16])</button></form>
   </div>
 </td></tr>
 @empty<tr><td colspan="9" class="empty-cell">Nenhum produto encontrado. Cadastre o primeiro produto.</td></tr>@endforelse
 </tbody></table></div>
 @include('partials.table-footer',['paginator'=>$products])
</section>
@endsection
