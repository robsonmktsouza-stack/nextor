@extends('layouts.app')
@section('title','Produtos')
@section('description','Cadastre e organize os itens comercializados, com controle de preços e quantidades.')
@section('content')
<section class="cms-card">
 <div class="grid-actionbar">
   <div class="grid-actions-left">
     <button class="grid-primary-action" type="button" data-dialog-open="product-create">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Novo</span></button>
     <div class="grid-tool-group">
       <button class="grid-tool grid-tool-wide" type="button" data-bulk-submit="products-duplicate" disabled title="Duplicar selecionados">@include('partials.icon',['name'=>'copy','size'=>18])<span>Duplicar</span></button>
       <button class="grid-tool grid-tool-danger" type="button" data-bulk-submit="products-delete" data-confirm="Excluir os produtos selecionados? Produtos com estoque ou movimentações serão preservados." disabled title="Excluir selecionados">@include('partials.icon',['name'=>'trash','size'=>18])</button>
       <button class="grid-tool" type="button" data-print-page title="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
       <button class="grid-tool" type="button" data-export-table="products.csv" title="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
       <button class="grid-tool" type="button" data-refresh-page title="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
     </div>
     <span class="selection-count" data-selection-count hidden></span>
     <form id="products-duplicate" method="post" action="{{ route('products.bulk-duplicate') }}" hidden>@csrf</form>
     <form id="products-delete" method="post" action="{{ route('products.bulk-delete') }}" hidden>@csrf @method('DELETE')</form>
   </div>
   <div class="grid-actions-right">
     <button class="grid-filter-button" type="button" data-filter-toggle="product-filters" title="Filtro avançado" aria-label="Filtro avançado">@include('partials.icon',['name'=>'search','size'=>18])</button>
   </div>
 </div>
 <div class="grid-filter-panel" id="product-filters" @if(!$term) hidden @endif>
   <form method="get" class="toolbar-filters"><label class="table-search">@include('partials.icon',['name'=>'search','size'=>16])<input name="search" value="{{ $term }}" placeholder="Buscar por nome ou código..."></label><button class="btn btn-secondary">Pesquisar</button>@if($term)<a class="btn btn-light" href="{{ route('products.index') }}">Limpar</a>@endif</form>
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
 <td class="action-cell"><button type="button" class="btn-icon" title="Editar produto" aria-label="Editar produto {{ $product->name }}" data-dialog-open="product-edit-{{ $product->id }}">@include('partials.icon',['name'=>'edit','size'=>16])</button></td></tr>
 @empty<tr><td colspan="9" class="empty-cell">Nenhum produto encontrado. Cadastre o primeiro produto.</td></tr>@endforelse
 </tbody></table></div>
 <div class="card-pagination">{{ $products->links('partials.pagination') }}</div>
</section>
<dialog class="erp-dialog" id="product-create" aria-labelledby="new-product-title"><form method="POST" action="{{ route('products.store') }}">@csrf
<div class="dialog-header"><div><h2 id="new-product-title">Novo produto</h2><p>Dados cadastrais e comerciais</p></div><button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">@include('partials.icon',['name'=>'x'])</button></div>
<div class="dialog-body"><div class="form-grid">
<label class="field"><span>SKU / código *</span><input name="sku" value="{{ old('sku') }}" required maxlength="80" placeholder="Ex.: PROD-001"></label>
<label class="field"><span>Nome do produto *</span><input name="name" value="{{ old('name') }}" required maxlength="190"></label>
<label class="field"><span>Categoria</span><input name="category" value="{{ old('category') }}"></label>
<label class="field"><span>Unidade *</span><select name="unit"><option value="UN">UN — Unidade</option><option value="KG">KG — Quilograma</option><option value="M">M — Metro</option><option value="CX">CX — Caixa</option><option value="L">L — Litro</option><option value="M2">M2 — Metro quadrado</option><option value="M3">M3 — Metro cúbico</option></select></label>
<label class="field"><span>Custo unitário (R$) *</span><input type="number" step="0.01" min="0" name="cost_price" value="{{ old('cost_price','0.00') }}" required></label>
<label class="field"><span>Preço de venda (R$) *</span><input type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price','0.00') }}" required></label>
<label class="field"><span>Estoque mínimo *</span><input type="number" step="0.001" min="0" name="minimum_stock" value="{{ old('minimum_stock','0') }}" required></label>
<div class="field"><span>Estoque disponível</span><div class="readonly-field">0,000 <small>Faça a entrada no módulo Estoque</small></div></div>
<label class="field wide"><span>Descrição</span><textarea name="description" rows="2">{{ old('description') }}</textarea></label>
<input type="hidden" name="is_active" value="1">
</div></div><div class="dialog-footer"><button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button><button class="btn btn-primary">Salvar produto</button></div>
</form></dialog>
@foreach($products as $product)
<dialog class="erp-dialog" id="product-edit-{{ $product->id }}" aria-labelledby="edit-title-{{ $product->id }}"><form method="POST" action="{{ route('products.update',$product) }}">@csrf @method('PUT')
<div class="dialog-header"><div><h2 id="edit-title-{{ $product->id }}">Editar produto</h2><p>{{ $product->name }}</p></div><button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">@include('partials.icon',['name'=>'x'])</button></div>
<div class="dialog-body"><div class="form-grid">
<label class="field"><span>SKU / código *</span><input name="sku" value="{{ $product->sku }}" required maxlength="80"></label>
<label class="field"><span>Nome *</span><input name="name" value="{{ $product->name }}" required></label>
<label class="field"><span>Categoria</span><input name="category" value="{{ $product->category }}"></label>
<label class="field"><span>Unidade *</span><select name="unit">@foreach(['UN','KG','M','CX','L','M2','M3'] as $unit)<option value="{{ $unit }}" @selected($product->unit===$unit)>{{ $unit }}</option>@endforeach</select></label>
<label class="field"><span>Custo unitário (R$) *</span><input type="number" step="0.01" min="0" name="cost_price" value="{{ $product->cost_price }}" required></label>
<label class="field"><span>Preço de venda (R$) *</span><input type="number" step="0.01" min="0" name="sale_price" value="{{ $product->sale_price }}" required></label>
<label class="field"><span>Estoque mínimo *</span><input type="number" step="0.001" min="0" name="minimum_stock" value="{{ $product->minimum_stock }}" required></label>
<label class="field"><span>Status</span><select name="is_active"><option value="1" @selected($product->is_active)>Ativo</option><option value="0" @selected(!$product->is_active)>Inativo</option></select></label>
<label class="field wide"><span>Descrição</span><textarea name="description" rows="2">{{ $product->description }}</textarea></label>
</div><p class="inline-note">Para alterar a quantidade disponível, use uma entrada, saída ou ajuste no módulo Estoque.</p></div>
<div class="dialog-footer"><button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button><button class="btn btn-primary">Salvar alterações</button></div></form></dialog>
@endforeach
@if($errors->any())@push('scripts')<script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('product-create')?.showModal());</script>@endpush
@endif
@endsection
