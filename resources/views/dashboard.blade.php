@extends('layouts.app')
@section('title','Visão geral')
@section('actions')@if($licensedPdv)<a class="btn btn-success grid-primary-action" href="{{ route('sales.create') }}">@include('partials.icon',['name'=>'plus','size'=>16]) Nova venda</a>@endif @endsection
@section('content')
<div class="lumeron-dashboard">\n<div class="stats-grid">
 @if($licensedProducts)
 <a class="stat-card" href="{{ route('products.index') }}"><span class="stat-icon blue">@include('partials.icon',['name'=>'products','size'=>21])</span><span class="stat-label">Produtos ativos</span><strong>{{ number_format($productsCount,0,',','.') }}</strong><small>Itens cadastrados</small></a>
 <a class="stat-card" href="{{ route('stock.index') }}"><span class="stat-icon violet">@include('partials.icon',['name'=>'layers','size'=>21])</span><span class="stat-label">Valor do estoque (custo)</span><strong>R$ {{ number_format((float)$stockValue,2,',','.') }}</strong><small>Quantidade × custo</small></a>
 <a class="stat-card" href="{{ route('products.index') }}"><span class="stat-icon orange">@include('partials.icon',['name'=>'alert','size'=>21])</span><span class="stat-label">Estoque baixo</span><strong>{{ $lowStock }}</strong><small>Produtos no mínimo ou abaixo</small></a>
 @endif
 @if($licensedPdv)
 <a class="stat-card" href="{{ route('sales.index') }}"><span class="stat-icon green">@include('partials.icon',['name'=>'money','size'=>21])</span><span class="stat-label">Vendas concluídas</span><strong>R$ {{ number_format((float)$salesTotal,2,',','.') }}</strong><small>{{ $salesCount }} {{ $salesCount===1?'venda concluída':'vendas concluídas' }}</small></a>
 @endif
</div>
<div class="dashboard-grid">
 @if($licensedPdv)
 <section class="cms-card"><div class="card-header"><div><h2>Últimas vendas</h2></div><a class="minor-link" href="{{ route('sales.index') }}">Ver todas →</a></div>
 <div class="table-scroll"><table class="cms-table"><thead><tr><th>Venda</th><th>Cliente</th><th>Valor</th><th>Status</th></tr></thead><tbody>
 @forelse($recentSales as $sale)<tr><td><a class="table-link" href="{{ route('sales.show',$sale) }}">#{{ str_pad($sale->id,5,'0',STR_PAD_LEFT) }}</a></td><td>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</td><td class="nowrap">R$ {{ number_format((float)$sale->total,2,',','.') }}</td><td><span class="status {{ $sale->status==='completed'?'status-ok':'status-muted' }}">{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</span></td></tr>
 @empty<tr><td colspan="4" class="empty-cell">Nenhuma venda registrada ainda.</td></tr>@endforelse
 </tbody></table></div></section>
 @endif
 @if($licensedProducts)
 <section class="cms-card"><div class="card-header"><div><h2>Atenção ao estoque</h2></div><a class="minor-link" href="{{ route('stock.index') }}">Movimentar →</a></div>
 <div class="table-scroll"><table class="cms-table"><thead><tr><th>Produto</th><th>Disponível</th></tr></thead><tbody>
 @forelse($lowProducts as $product)<tr><td><strong class="table-title">{{ $product->name }}</strong><small class="table-subtitle">{{ $product->sku }}</small></td><td><span class="stock-low">{{ number_format((float)$product->stock_quantity,3,',','.') }} {{ $product->unit }}</span></td></tr>
 @empty<tr><td colspan="2" class="empty-cell">Nenhum produto abaixo do mínimo.</td></tr>@endforelse
 </tbody></table></div></section>
 @endif
</div>
@if($licensedPdv)
@php
  $trendMax = max(1, (float) $salesTrend->max('total'));
  $trendPoints = $salesTrend->values()->map(fn ($item,$index) => ($index * 100).','.round(100-((float)$item['total']/$trendMax)*85,2))->implode(' ');
@endphp
<section class="cms-card lumeron-chart-card" aria-label="Evolução das vendas dos últimos seis meses">
  <div class="card-header"><div><h2>Evolução das vendas</h2><p>Vendas concluídas nos últimos seis meses</p></div><a class="minor-link" href="{{ route('sales.index') }}">Ver vendas →</a></div>
  <div class="lumeron-chart-content">
    <div class="lumeron-chart-plot">
      <svg viewBox="0 0 500 120" preserveAspectRatio="none" role="img" aria-label="Linha de evolução mensal das vendas">
        <line x1="0" y1="115" x2="500" y2="115" stroke="#dce6ed" stroke-width="1" vector-effect="non-scaling-stroke"/>
        <polyline points="{{ $salesTrend->values()->map(fn ($item,$index) => ($index * 100).','.round(100-((float)$item['total']/$trendMax)*85,2))->implode(' ') }}" fill="none" stroke="#287fa9" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
        @foreach($salesTrend->values() as $index=>$item)
          <circle cx="{{ $index*100 }}" cy="{{ round(100-((float)$item['total']/$trendMax)*85,2) }}" r="4" fill="#287fa9" stroke="white" stroke-width="2" vector-effect="non-scaling-stroke"/>
        @endforeach
      </svg>
    </div>
    <div class="lumeron-chart-labels">
      @foreach($salesTrend as $item)
        <div><strong>{{ ucfirst($item['label']) }}</strong><span>R$ {{ number_format($item['total'],2,',','.') }}</span></div>
      @endforeach
    </div>
  </div>
</section>
@endif
<div class="quick-row"><span>ACESSOS RÁPIDOS</span>
@if($licensedProducts)
<a href="{{ route('products.index') }}">Produtos @include('partials.icon',['name'=>'chevron','size'=>14])</a>
<a href="{{ route('stock.index') }}">Estoque @include('partials.icon',['name'=>'chevron','size'=>14])</a>
@endif
@if($licensedPdv)
<a href="{{ route('customers.index') }}">Clientes @include('partials.icon',['name'=>'chevron','size'=>14])</a>
<a href="{{ route('sales.create') }}">Nova venda @include('partials.icon',['name'=>'chevron','size'=>14])</a>
@endif
@if(\App\Support\CommercialModuleAccess::visible(request()->attributes->get('lumeron_commercial_license', []),'finance') && auth()->user()->canAccess('finance'))
<a href="{{ route('finance.dashboard') }}">Financeiro @include('partials.icon',['name'=>'chevron','size'=>14])</a>
@endif
</div>
</div>
@push('styles')
<style>
.lumeron-dashboard .lumeron-chart-card{margin-top:16px;border:1px solid #d5dfe8;border-radius:4px;background:#fff;overflow:hidden}
.lumeron-dashboard .lumeron-chart-card .card-header{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;border-bottom:1px solid #e3eaf0}
.lumeron-dashboard .lumeron-chart-card .card-header h2{margin:0;font-size:14px}
.lumeron-dashboard .lumeron-chart-card .card-header p{margin:3px 0 0;font-size:11px;color:#708398}
.lumeron-dashboard .lumeron-chart-content{padding:18px 20px 15px}
.lumeron-dashboard .lumeron-chart-plot{height:155px;padding:8px 8px 0}
.lumeron-dashboard .lumeron-chart-plot svg{display:block;width:100%;height:100%;overflow:visible}
.lumeron-dashboard .lumeron-chart-labels{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:6px;margin-top:12px;text-align:center}
.lumeron-dashboard .lumeron-chart-labels div{display:flex;flex-direction:column;gap:4px;min-width:0}
.lumeron-dashboard .lumeron-chart-labels strong{font-size:11px;color:#516d83}
.lumeron-dashboard .lumeron-chart-labels span{font-size:11px;color:#203b50;white-space:nowrap}
@media(max-width:700px){.lumeron-dashboard .lumeron-chart-content{overflow-x:auto}.lumeron-dashboard .lumeron-chart-plot,.lumeron-dashboard .lumeron-chart-labels{min-width:580px}}
/* Dashboard: acabamento compacto e consistente, restrito à visão geral. */
.lumeron-dashboard{--dash-border:#d5dfe8;--dash-text:#17334b;--dash-muted:#708398}
.lumeron-dashboard .stats-grid{gap:14px;margin-bottom:18px;align-items:stretch}
.lumeron-dashboard .stat-card{position:relative;min-height:124px;padding:17px 18px 14px;border:1px solid var(--dash-border);border-top:3px solid #82a9c7;border-radius:4px;background:#fff;box-shadow:0 1px 3px rgba(25,55,78,.045);display:grid;grid-template-columns:minmax(0,1fr) 34px;grid-template-rows:auto auto auto;column-gap:12px;row-gap:5px;align-content:start;transition:border-color .15s,box-shadow .15s}
.lumeron-dashboard .stat-card:hover{border-color:#96b7d0;box-shadow:0 3px 9px rgba(25,55,78,.085)}
.lumeron-dashboard .stat-card .stat-icon{grid-column:2;grid-row:1 / span 2;width:34px;height:34px;border-radius:4px;display:flex;align-items:center;justify-content:center;margin:0}
.lumeron-dashboard .stat-card .stat-icon svg{width:18px;height:18px}
.lumeron-dashboard .stat-card .stat-label{grid-column:1;grid-row:1;align-self:center;margin:0;font-size:12px;font-weight:600;color:#58718a}
.lumeron-dashboard .stat-card strong{grid-column:1;grid-row:2;margin:0;font-size:25px;line-height:1.22;letter-spacing:-.5px;color:var(--dash-text);font-weight:750}
.lumeron-dashboard .stat-card small{grid-column:1 / -1;grid-row:3;margin-top:4px;font-size:11px;color:var(--dash-muted)}
.lumeron-dashboard .dashboard-grid{grid-template-columns:minmax(0,1.65fr) minmax(320px,1fr);gap:16px;align-items:start}
.lumeron-dashboard .dashboard-grid>.cms-card{border:1px solid var(--dash-border);border-radius:4px;box-shadow:0 1px 3px rgba(25,55,78,.045);overflow:hidden;background:#fff}
.lumeron-dashboard .dashboard-grid .card-header{min-height:48px;padding:11px 15px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--dash-border);background:#fff}
.lumeron-dashboard .dashboard-grid .card-header h2{font-size:14px;font-weight:700;color:var(--dash-text);margin:0}
.lumeron-dashboard .dashboard-grid .minor-link{font-size:12px;font-weight:600;text-decoration:none}
.lumeron-dashboard .dashboard-grid .table-scroll{border:0;border-radius:0;overflow-x:auto}
.lumeron-dashboard .dashboard-grid .cms-table{width:100%;border-collapse:collapse}
.lumeron-dashboard .dashboard-grid .cms-table thead th{padding:11px 13px;background:#f3f7fa;border-bottom:1px solid var(--dash-border);font-size:11px;letter-spacing:.025em;color:#526f87;text-transform:uppercase}
.lumeron-dashboard .dashboard-grid .cms-table tbody td{padding:10px 13px;border-bottom:1px solid #e9eef3;font-size:12px;vertical-align:middle}
.lumeron-dashboard .dashboard-grid .cms-table tbody tr:last-child td{border-bottom:0}
.lumeron-dashboard .dashboard-grid .cms-table tbody tr:hover{background:#f8fbfd}
.lumeron-dashboard .dashboard-grid .cms-table .table-title{font-size:12px;line-height:1.35}
.lumeron-dashboard .dashboard-grid .cms-table .table-subtitle{font-size:11px;color:#8093a5;margin-top:3px}
.lumeron-dashboard .dashboard-grid .cms-table .stock-low{font-weight:700;color:#b96218;white-space:nowrap}
.lumeron-dashboard .dashboard-grid .cms-table .status{font-size:11px}
.lumeron-dashboard .dashboard-grid .cms-table th:last-child,.lumeron-dashboard .dashboard-grid .cms-table td:last-child{text-align:right}
.lumeron-dashboard .quick-row{margin-top:18px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.lumeron-dashboard .quick-row>span{font-size:11px;letter-spacing:.04em;color:#71879b;margin-right:4px}
.lumeron-dashboard .quick-row>a{border:1px solid var(--dash-border);border-radius:4px;background:#fff;padding:8px 11px;font-size:12px;white-space:nowrap}
@media(max-width:1100px){.lumeron-dashboard .dashboard-grid{grid-template-columns:minmax(0,1fr)}.lumeron-dashboard .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.lumeron-dashboard .stats-grid{grid-template-columns:minmax(0,1fr)}.lumeron-dashboard .stat-card{min-height:105px}}
</style>
@endpush
@endsection
