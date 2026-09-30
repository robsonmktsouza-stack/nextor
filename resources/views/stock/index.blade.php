@extends('layouts.app')
@section('title','Estoque')
@section('description','Controle de entradas, saídas e ajustes com histórico completo por produto.')
@section('actions')<button class="btn btn-primary" type="button" data-dialog-open="stock-add">@include('partials.icon',['name'=>'plus','size'=>16]) Nova movimentação</button>@endsection
@section('content')
<section class="cms-card"><div class="table-toolbar"><div><h2>Histórico de movimentações</h2><p>Registros auditáveis de alteração de quantidade</p></div>
<form method="get" class="toolbar-filters"><select class="input-filter" name="product_id"><option value="">Todos os produtos</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected($productId===$product->id)>{{ $product->name }} ({{ $product->sku }})</option>@endforeach</select><button class="btn btn-secondary">Filtrar</button>@if($productId)<a class="btn btn-light" href="{{ route('stock.index') }}">Limpar</a>@endif</form></div>
<div class="table-scroll"><table class="cms-table"><thead><tr><th>Data</th><th>Produto</th><th>Operação</th><th>Alteração</th><th>Saldo anterior</th><th>Saldo novo</th><th>Motivo</th><th>Responsável</th></tr></thead><tbody>
@forelse($movements as $movement)<tr><td class="nowrap">{{ $movement->created_at?->format('d/m/Y H:i') }}</td><td><strong class="table-title">{{ $movement->product?->name }}</strong><small class="table-subtitle">{{ $movement->product?->sku }}</small></td><td>
@php($labels=['entry'=>'Entrada','exit'=>'Saída','adjustment'=>'Ajuste','sale'=>'Venda','sale_cancel'=>'Estorno'])
<span class="status {{ in_array($movement->type,['entry','sale_cancel'])?'status-ok':($movement->type==='sale'?'status-blue':'status-muted') }}">{{ $labels[$movement->type] ?? $movement->type }}</span></td><td class="nowrap {{ (float)$movement->quantity_delta>0?'positive':'negative' }}">{{ (float)$movement->quantity_delta>0?'+':'' }}{{ number_format((float)$movement->quantity_delta,3,',','.') }}</td><td>{{ number_format((float)$movement->previous_quantity,3,',','.') }}</td><td class="price-strong">{{ number_format((float)$movement->new_quantity,3,',','.') }}</td><td>{{ $movement->reason }}</td><td>{{ $movement->user?->name ?? 'Sistema' }}</td></tr>
@empty<tr><td class="empty-cell" colspan="8">Nenhuma movimentação registrada.</td></tr>@endforelse
</tbody></table></div><div class="card-pagination">{{ $movements->links('partials.pagination') }}</div></section>
<dialog class="erp-dialog" id="stock-add"><form action="{{ route('stock.store') }}" method="post">@csrf
<div class="dialog-header"><div><h2>Nova movimentação</h2><p>Atualize as quantidades disponíveis</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
<div class="dialog-body"><div class="form-grid">
<label class="field wide"><span>Produto *</span><select name="product_id" required><option value="">Selecione um produto</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('product_id')==$product->id)>{{ $product->sku }} — {{ $product->name }} ({{ number_format((float)$product->stock_quantity,3,',','.') }} {{ $product->unit }})</option>@endforeach</select></label>
<label class="field"><span>Tipo de movimentação *</span><select name="type" id="stock-type"><option value="entry">Entrada</option><option value="exit">Saída</option><option value="adjustment">Ajuste de saldo</option></select></label>
<label class="field"><span id="stock-qty-label">Quantidade *</span><input name="quantity" type="number" min="0" step="0.001" required value="{{ old('quantity') }}"></label>
<label class="field wide"><span>Motivo / observação *</span><input name="reason" required maxlength="255" value="{{ old('reason') }}" placeholder="Ex.: Compra de mercadorias, ajuste de inventário..."></label>
</div><p class="inline-note">Em um ajuste, informe a quantidade final desejada. O sistema calculará a diferença e registrará a operação.</p></div>
<div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button class="btn btn-primary">Registrar movimentação</button></div></form></dialog>
@if($errors->any())@push('scripts')<script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('stock-add')?.showModal());</script>@endpush
@endif
@endsection
