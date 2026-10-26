@extends('layouts.app')
@section('title','Vendas')
@section('description','Histórico de pedidos e operações comerciais com baixa automática de estoque.')
@section('content')
@include('sales._nav')
<section class="cms-card sales-module-card">
<div class="grid-actionbar">
  <div class="grid-actions-left">
    <a class="grid-primary-action" href="{{ route('sales.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span></a>
    <div class="grid-tool-group">
      <button class="grid-tool" type="button" id="sales-print-open" data-tooltip="Opções de impressão" aria-label="Opções de impressão">@include('partials.icon',['name'=>'print','size'=>18])</button>
      <button class="grid-tool" type="button" data-export-table="vendas.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
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
  </div>
  <div class="grid-actions-right">
    <a class="period-current" href="{{ route('sales.index', array_merge(request()->except('page','month'), ['month'=>now()->format('Y-m')])) }}">Mês atual</a>
    <a class="period-arrow" href="{{ route('sales.index', array_merge(request()->except('page','month'), ['month'=>$prevMonth])) }}" aria-label="Mês anterior">@include('partials.icon',['name'=>'chevron-left','size'=>19])</a>
    <span class="period-label">{{ $monthLabel }}</span>
    <a class="period-arrow" href="{{ route('sales.index', array_merge(request()->except('page','month'), ['month'=>$nextMonth])) }}" aria-label="Próximo mês">@include('partials.icon',['name'=>'chevron','size'=>19])</a>
    <button class="grid-filter-button" type="button" data-filter-toggle="sales-filters" data-tooltip="Filtro avançado" aria-label="Filtro avançado">@include('partials.icon',['name'=>'search','size'=>18])</button>
  </div>
</div>
<div class="grid-filter-panel" id="sales-filters" @if(!$term && !$status) hidden @endif>
<form method="get" action="{{ route('sales.index') }}" class="toolbar-filters" data-live-search data-live-target="sales-live-results">
  <input type="hidden" name="month" value="{{ $month }}">
  <div class="table-search-group">
    <input type="search" autocomplete="off" name="search" value="{{ $term }}" placeholder="Código, cliente, palavra-chave ou responsável..." aria-label="Buscar vendas">
    <button type="submit" class="table-search-submit" data-tooltip="Pesquisar">@include('partials.icon',['name'=>'search','size'=>17])</button>
  </div>
  <select name="status" class="input-filter"><option value="">Todos os status</option><option value="completed" @selected($status==='completed')>Concluídas</option><option value="cancelled" @selected($status==='cancelled')>Canceladas</option><option value="converted" @selected($status==='converted')>Convertidos</option></select>
  <button class="btn btn-secondary">Filtrar</button>
  @if($term || $status)<a class="btn btn-light" href="{{ route('sales.index',['month'=>$month]) }}">Limpar</a>@endif
</form>
</div>
<div id="sales-live-results" data-live-search-results><div class="table-scroll"><table class="cms-table"><thead><tr><th class="select-cell"><input type="checkbox" data-check-all aria-label="Selecionar todos"></th><th>Nº</th><th>Tipo</th><th>Data</th><th>Cliente</th><th>Responsável</th><th>Valor total</th><th>Status</th><th class="action-cell">Detalhes</th></tr></thead><tbody>
@forelse($sales as $sale)<tr><td class="select-cell"><input type="checkbox" data-row-select value="{{ $sale->id }}" aria-label="Selecionar venda {{ $sale->id }}"></td><td><a class="table-link" href="{{ route('sales.show',$sale) }}">#{{ str_pad($sale->id,5,'0',STR_PAD_LEFT) }}</a></td><td><span class="status status-blue">{{ $sale->operation_type==='quote'?'Orçamento':'Venda' }}</span></td><td class="nowrap">{{ ($sale->operation_date ?? $sale->created_at)->format(\App\Models\AppSetting::dateFormat()) }}</td><td>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</td><td>{{ $sale->user?->name ?? '—' }}</td><td class="price-strong nowrap">R$ {{ number_format((float)$sale->total,2,',','.') }}</td><td><span class="status {{ $sale->status==='completed'?'status-ok':'status-muted' }}">{{ match($sale->status){'converted'=>'Convertido','cancelled'=>'Cancelado',default=>$sale->operation_type==='quote'?'Em aberto':'Concluída'} }}</span></td><td class="action-cell">
 @if($sale->operation_type==='quote')
 <a class="btn-icon" href="{{ route('sales.quote.pdf',$sale) }}" target="_blank" rel="noopener noreferrer" title="Visualizar orçamento PDF" aria-label="Visualizar PDF">@include('partials.icon',['name'=>'print','size'=>17])</a>
 @endif
 @if($sale->operation_type==='sale')
 <a class="btn-icon" href="{{ route('sales.receipt',$sale) }}" target="_blank" rel="noopener noreferrer" title="Comprovante de venda (não fiscal)" aria-label="Imprimir comprovante de venda">@include('partials.icon',['name'=>'print','size'=>17])</a>
 @endif
 <a class="btn-icon" href="{{ route('sales.show',$sale) }}" aria-label="Abrir operação">@include('partials.icon',['name'=>'chevron','size'=>17])</a>
 </td></tr>
@empty<tr><td class="empty-cell" colspan="9">Nenhuma venda ou orçamento cadastrado.</td></tr>@endforelse
</tbody></table></div>@include('partials.table-footer',['paginator'=>$sales])</div>
</section>
<dialog id="sales-print-dialog" class="sales-print-dialog" aria-labelledby="sales-print-title">
 <form method="dialog"><div class="sales-print-head"><h2 id="sales-print-title">Imprimir vendas</h2><button type="submit" aria-label="Fechar">×</button></div></form>
 <div class="sales-print-body">
  <label>O que deseja imprimir?</label>
  <div class="sales-print-options"><label><input type="radio" name="sales-print-type" value="list" checked> Imprimir listagem</label><label><input type="radio" name="sales-print-type" value="sale"> Imprimir venda</label></div>
  <div id="sales-print-sale-picker" hidden><label for="sales-print-sale">Venda</label><select id="sales-print-sale"><option value="">Selecione uma venda</option>
  @foreach($sales as $sale) @if($sale->operation_type==='sale') <option value="{{ $sale->id }}" data-receipt="{{ route('sales.receipt',$sale) }}" data-a4="{{ route('sales.receipt',$sale) }}?format=a4">#{{ str_pad($sale->id,5,'0',STR_PAD_LEFT) }} — {{ $sale->customer?->name ?? 'Consumidor não identificado' }} — R$ {{ number_format((float)$sale->total,2,',','.') }}</option> @endif @endforeach
  </select><small>Vendas exibidas nesta página.</small></div>
  <label>Modelo de impressão</label><div class="sales-print-options"><label><input type="radio" name="sales-print-format" value="a4" checked> A4</label><label><input type="radio" name="sales-print-format" value="80"> 80 mm (térmica)</label></div>
  <p id="sales-print-error" role="alert" hidden></p>
 </div>
 <div class="sales-print-footer"><button type="button" id="sales-print-cancel">Cancelar</button><button type="button" id="sales-print-confirm">Visualizar impressão</button></div>
</dialog>
@push('styles')
<style>
.sales-print-dialog{width:min(440px,calc(100vw - 32px));padding:0;border:1px solid #bdcbd6;border-radius:4px;box-shadow:0 14px 45px #102b3b35;color:#21394e}
.sales-print-dialog::backdrop{background:#142b3a88}
.sales-print-head,.sales-print-footer{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;background:#f5f8fa;border-bottom:1px solid #dbe4eb}
.sales-print-head h2{font-size:15px;margin:0}.sales-print-head button{border:0;background:transparent;font-size:22px;cursor:pointer}
.sales-print-body{padding:18px;display:grid;gap:12px}.sales-print-body>label,.sales-print-body #sales-print-sale-picker>label{font-size:12px;font-weight:700}
.sales-print-options{display:flex;gap:10px}.sales-print-options label{flex:1;border:1px solid #cbd8e2;padding:10px;border-radius:4px;font-size:12px;cursor:pointer}
.sales-print-options input{vertical-align:middle;margin-right:5px}
#sales-print-sale-picker select{display:block;width:100%;padding:9px;margin-top:6px;border:1px solid #cbd8e2;border-radius:4px}
#sales-print-sale-picker small{font-size:11px;color:#6a7d8c}#sales-print-error{color:#b3342b;font-size:12px;margin:0}
.sales-print-footer{justify-content:flex-end;gap:8px;border-bottom:0;border-top:1px solid #dbe4eb}
.sales-print-footer button{padding:9px 13px;border:1px solid #cbd8e2;border-radius:4px;background:#fff;cursor:pointer}
#sales-print-confirm{background:#2478a5;color:#fff;border-color:#2478a5}
</style>
@endpush
@push('scripts')
<script>
(()=>{
const dialog=document.getElementById('sales-print-dialog'),picker=document.getElementById('sales-print-sale-picker'),error=document.getElementById('sales-print-error');
if(!dialog)return;
const selected=name=>dialog.querySelector('input[name="'+name+'"]:checked')?.value;
const sync=()=>{picker.hidden=selected('sales-print-type')!=='sale';error.hidden=true};
dialog.querySelectorAll('input[type="radio"]').forEach(el=>el.addEventListener('change',sync));
document.getElementById('sales-print-open')?.addEventListener('click',()=>{sync();dialog.showModal()});
document.getElementById('sales-print-cancel')?.addEventListener('click',()=>dialog.close());
document.getElementById('sales-print-confirm')?.addEventListener('click',()=>{
 const format=selected('sales-print-format');
 if(selected('sales-print-type')==='sale'){
  const opt=document.getElementById('sales-print-sale').selectedOptions[0];
  if(!opt?.value){error.textContent='Selecione uma venda.';error.hidden=false;return}
  window.open(format==='a4'?opt.dataset.a4:opt.dataset.receipt,'_blank','noopener');dialog.close();return;
 }
 const table=document.querySelector('#sales-live-results table');
 if(!table){error.textContent='A listagem não está disponível.';error.hidden=false;return}
 const clone=table.cloneNode(true);
 clone.querySelectorAll('th:first-child,td:first-child,th:last-child,td:last-child').forEach(el=>el.remove());
 const popup=window.open('','_blank');
 if(!popup){error.textContent='Permita janelas pop-up para imprimir.';error.hidden=false;return}
 const style='@page{size:'+(format==='80'?'80mm auto':'A4 portrait')+';margin:'+(format==='80'?'3mm':'12mm')+'}body{font:12px Arial;color:#111;margin:0}h1{font-size:16px}table{width:100%;border-collapse:collapse}th,td{padding:7px 4px;border-bottom:1px solid #ccc;text-align:left}th{background:#eee}a{color:inherit;text-decoration:none}.status{background:none!important;color:#111!important}'+(format==='80'?'body{font-size:9px}h1{font-size:12px}th,td{padding:4px 2px;font-size:8px;overflow-wrap:anywhere}':'');
 popup.document.write('<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Listagem de vendas</title><style>'+style+'</style></head><body><h1>Listagem de vendas — {{ $monthLabel }}</h1>'+clone.outerHTML+'<script>window.onload=function(){window.print()}<\/script></body></html>');
 popup.document.close();dialog.close();
});
})();
</script>
@endpush
@endsection
