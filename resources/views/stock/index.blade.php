@extends('layouts.app')
@section('titleMeta'){{ $movements->total() }} {{ $movements->total() === 1 ? 'movimentação' : 'movimentações' }}@endsection
@section('title','Estoque')
@section('description','Controle de entradas, saídas e ajustes com histórico completo por produto.')
@section('content')
<section class="cms-card">
<div class="grid-actionbar">
  <div class="grid-actions-left">
    <button class="grid-primary-action" type="button" data-dialog-open="stock-add">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span></button>
    <div class="grid-tool-group">
      <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
      <button class="grid-tool" type="button" data-export-table="estoque.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
      <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
    </div>
    <div class="bulk-actions">
      <select class="bulk-action-select" data-bulk-menu disabled aria-label="Ações em massa">
        <option value="">Ações em massa</option>
        <option value="local:export">Exportar selecionadas</option>
        <option value="local:print">Imprimir selecionadas</option>
      </select>
      <button class="bulk-apply" type="button" data-bulk-apply disabled>Aplicar</button>
    </div>
    <span class="selection-count" data-selection-count hidden></span>
  </div>
  @if($minimumStockAlerts && $lowStockCount>0)
    <div class="stock-alert-chip" data-tooltip="{{ $lowStockCount }} produto(s) no estoque mínimo">
      @include('partials.icon',['name'=>'alert','size'=>15])
      <span>{{ $lowStockCount }} no mínimo</span>
    </div>
  @endif
  <div class="grid-actions-right">
    <a class="period-current" href="{{ route('stock.index', array_merge(request()->except('page','month'), ['month'=>now()->format('Y-m')])) }}">Mês atual</a>
    <a class="period-arrow" href="{{ route('stock.index', array_merge(request()->except('page','month'), ['month'=>$prevMonth])) }}" aria-label="Mês anterior">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
    <span class="period-label">{{ $monthLabel }}</span>
    <a class="period-arrow" href="{{ route('stock.index', array_merge(request()->except('page','month'), ['month'=>$nextMonth])) }}" aria-label="Próximo mês">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
    <button class="grid-filter-button" type="button" data-filter-toggle="stock-filters" data-tooltip="Filtro avançado" aria-label="Filtro avançado">@include('partials.icon',['name'=>'search','size'=>18])</button>
  </div>
</div>
<div class="grid-filter-panel" id="stock-filters" @if(!$productId && !$term) hidden @endif>
<form method="get" action="{{ route('stock.index') }}" class="toolbar-filters" data-live-search data-live-target="stock-live-results">
  <input type="hidden" name="month" value="{{ $month }}">
  <div class="table-search-group">
    <input type="search" autocomplete="off" name="search" value="{{ $term }}" placeholder="Produto, SKU, motivo ou responsável..." aria-label="Buscar movimentações">
    <button type="submit" class="table-search-submit" data-tooltip="Pesquisar">@include('partials.icon',['name'=>'search','size'=>17])</button>
  </div>
  <select class="input-filter" name="product_id"><option value="">Todos os produtos</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected($productId===$product->id)>{{ $product->name }} ({{ $product->sku }})</option>@endforeach</select>
  <button class="btn btn-secondary">Filtrar</button>
  @if($productId || $term)<a class="btn btn-light" href="{{ route('stock.index',['month'=>$month]) }}">Limpar</a>@endif
</form>
</div>
<div id="stock-live-results" data-live-search-results><div class="table-scroll"><table class="cms-table"><thead><tr><th class="select-cell"><input type="checkbox" data-check-all aria-label="Selecionar todos"></th><th>Data</th><th>Produto</th><th>Operação</th><th>Alteração</th><th>Saldo anterior</th><th>Saldo novo</th><th>Motivo</th><th>Responsável</th></tr></thead><tbody>
@forelse($movements as $movement)<tr><td class="select-cell"><input type="checkbox" data-row-select value="{{ $movement->id }}" aria-label="Selecionar movimentação {{ $movement->id }}"></td><td class="nowrap">{{ $movement->created_at?->format('d/m/Y H:i') }}</td><td><strong class="table-title">{{ $movement->product?->name }}</strong><small class="table-subtitle">{{ $movement->product?->sku }}</small></td><td>
@php($labels=['entry'=>'Entrada','exit'=>'Saída','adjustment'=>'Ajuste','sale'=>'Venda','sale_cancel'=>'Estorno','sale_return'=>'Devolução de venda','sale_return_cancel'=>'Cancelamento de devolução'])
<span class="status {{ in_array($movement->type,['entry','sale_cancel','sale_return'])?'status-ok':(in_array($movement->type,['sale','sale_return_cancel'])?'status-blue':'status-muted') }}">{{ $labels[$movement->type] ?? $movement->type }}</span></td><td class="nowrap {{ (float)$movement->quantity_delta>0?'positive':'negative' }}">{{ (float)$movement->quantity_delta>0?'+':'' }}{{ number_format((float)$movement->quantity_delta,$stockDecimalPlaces,',','.') }}</td><td>{{ number_format((float)$movement->previous_quantity,$stockDecimalPlaces,',','.') }}</td><td class="price-strong">{{ number_format((float)$movement->new_quantity,$stockDecimalPlaces,',','.') }}</td><td>{{ $movement->reason }}</td><td>{{ $movement->user?->name ?? 'Sistema' }}</td></tr>
@empty<tr><td class="empty-cell" colspan="9">Nenhuma movimentação registrada.</td></tr>@endforelse
</tbody></table></div>@include('partials.table-footer',['paginator'=>$movements])</div>
</section>
<dialog class="erp-dialog" id="stock-add"><form action="{{ route('stock.store') }}" method="post">@csrf
<div class="dialog-header"><div><h2>Nova movimentação</h2><p>Atualize as quantidades disponíveis</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
<div class="dialog-body"><div class="form-grid">
<label class="field wide"><span>Produto *</span><select name="product_id" required><option value="">Selecione um produto</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('product_id', request('product_id'))==$product->id)>{{ $product->sku }} — {{ $product->name }} ({{ number_format((float)$product->stock_quantity,$stockDecimalPlaces,',','.') }} {{ $product->unit }})</option>@endforeach</select></label>
<label class="field"><span>Tipo de movimentação *</span><select name="type" id="stock-type"><option value="entry">Entrada</option><option value="exit">Saída</option><option value="adjustment">Ajuste de saldo</option></select></label>
<label class="field"><span id="stock-qty-label">Quantidade *</span><input name="quantity" type="number" min="0" step="{{ $stockDecimalPlaces===0 ? '1' : '0.'.str_repeat('0',$stockDecimalPlaces-1).'1' }}" required value="{{ old('quantity') }}"></label>
<label class="field wide"><span>Motivo / observação</span><input name="reason" maxlength="255" value="{{ old('reason',$inventorySettings['default_adjustment_reason']) }}" placeholder="Ex.: Compra de mercadorias, ajuste de inventário..."></label>
</div><p class="inline-note">Em um ajuste, informe a quantidade final desejada. O sistema calculará a diferença e registrará a operação.</p></div>
<div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button class="btn btn-primary">Registrar movimentação</button></div></form></dialog>
@if($errors->any() || request('open'))@push('scripts')<script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('stock-add')?.showModal());</script>@endpush
@endif
@endsection
