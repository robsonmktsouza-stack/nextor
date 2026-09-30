@extends('layouts.app')
@section('title','Visão geral')
@section('description','Acompanhe as operações de estoque e vendas em um só lugar.')
@section('actions')<a class="btn btn-primary" href="{{ route('sales.create') }}">@include('partials.icon',['name'=>'plus','size'=>16]) Nova venda</a>@endsection
@section('content')
<div class="stats-grid">
 <a class="stat-card" href="{{ route('products.index') }}"><span class="stat-icon blue">@include('partials.icon',['name'=>'products','size'=>21])</span><span class="stat-label">Produtos ativos</span><strong>{{ number_format($productsCount,0,',','.') }}</strong><small>Itens cadastrados</small></a>
 <a class="stat-card" href="{{ route('stock.index') }}"><span class="stat-icon violet">@include('partials.icon',['name'=>'layers','size'=>21])</span><span class="stat-label">Valor do estoque (custo)</span><strong>R$ {{ number_format((float)$stockValue,2,',','.') }}</strong><small>Quantidade × custo</small></a>
 <a class="stat-card" href="{{ route('products.index') }}"><span class="stat-icon orange">@include('partials.icon',['name'=>'alert','size'=>21])</span><span class="stat-label">Estoque baixo</span><strong>{{ $lowStock }}</strong><small>Produtos no mínimo ou abaixo</small></a>
 <a class="stat-card" href="{{ route('sales.index') }}"><span class="stat-icon green">@include('partials.icon',['name'=>'money','size'=>21])</span><span class="stat-label">Vendas concluídas</span><strong>R$ {{ number_format((float)$salesTotal,2,',','.') }}</strong><small>{{ $salesCount }} venda(s) ativa(s)</small></a>
</div>
<div class="dashboard-grid">
 <section class="cms-card"><div class="card-header"><div><h2>Últimas vendas</h2><p>Histórico recente das operações</p></div><a class="minor-link" href="{{ route('sales.index') }}">Ver todas →</a></div>
 <div class="table-scroll"><table class="cms-table"><thead><tr><th>Venda</th><th>Cliente</th><th>Valor</th><th>Status</th></tr></thead><tbody>
 @forelse($recentSales as $sale)<tr><td><a class="table-link" href="{{ route('sales.show',$sale) }}">#{{ str_pad($sale->id,5,'0',STR_PAD_LEFT) }}</a></td><td>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</td><td class="nowrap">R$ {{ number_format((float)$sale->total,2,',','.') }}</td><td><span class="status {{ $sale->status==='completed'?'status-ok':'status-muted' }}">{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</span></td></tr>
 @empty<tr><td colspan="4" class="empty-cell">Nenhuma venda registrada ainda.</td></tr>@endforelse
 </tbody></table></div></section>
 <section class="cms-card"><div class="card-header"><div><h2>Atenção ao estoque</h2><p>Itens no limite mínimo</p></div><a class="minor-link" href="{{ route('stock.index') }}">Movimentar →</a></div>
 <div class="table-scroll"><table class="cms-table"><thead><tr><th>Produto</th><th>Disponível</th></tr></thead><tbody>
 @forelse($lowProducts as $product)<tr><td><strong class="table-title">{{ $product->name }}</strong><small class="table-subtitle">{{ $product->sku }}</small></td><td><span class="stock-low">{{ number_format((float)$product->stock_quantity,3,',','.') }} {{ $product->unit }}</span></td></tr>
 @empty<tr><td colspan="2" class="empty-cell">Nenhum produto abaixo do mínimo.</td></tr>@endforelse
 </tbody></table></div></section>
</div>
<div class="quick-row"><span>ACESSOS RÁPIDOS</span><a href="{{ route('products.index') }}">Produtos @include('partials.icon',['name'=>'chevron','size'=>14])</a><a href="{{ route('stock.index') }}">Estoque @include('partials.icon',['name'=>'chevron','size'=>14])</a><a href="{{ route('customers.index') }}">Clientes @include('partials.icon',['name'=>'chevron','size'=>14])</a><a href="{{ route('sales.create') }}">Nova venda @include('partials.icon',['name'=>'chevron','size'=>14])</a></div>
@endsection
