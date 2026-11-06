@extends('layouts.app')
@section('title','Vendas')
@section('description','Histórico de pedidos e operações comerciais com baixa automática de estoque.')
@section('content')
@include('sales._nav')
<section class="cms-card sales-module-card">
<div class="grid-actionbar">
  <div class="grid-actions-left">
    <a class="grid-primary-action" href="{{ route('sales.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span></a>
    <div class="grid-tool-group" style="position:relative">
      <button class="grid-tool" type="button" id="sales-print-open" data-tooltip="Opções de impressão" aria-label="Opções de impressão">@include('partials.icon',['name'=>'print','size'=>18])</button>
<div id="sales-print-dialog" class="sales-print-dropdown" hidden>
<strong>Imprimir</strong>
<button type="button" id="sales-print-list">Imprimir listagem</button>
<button type="button" id="sales-print-one">Imprimir venda selecionada</button>
<hr><span>Modelo</span>
<label><input type="radio" name="sales-print-format" value="a4" checked> A4</label>
<label><input type="radio" name="sales-print-format" value="80"> 80 mm</label>
<p id="sales-print-error" role="alert" hidden></p>
</div>
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
@push('styles')
<style>
.sales-print-dropdown{position:absolute;top:calc(100% + 4px);left:0;z-index:2000;width:240px;padding:10px;background:white;border:1px solid #cbd7e0;border-radius:4px;box-shadow:0 8px 22px #18374933;display:flex;flex-direction:column;gap:6px;color:#264057}
.sales-print-dropdown[hidden]{display:none!important}
.sales-print-dropdown strong{font-size:12px;padding:5px}
.sales-print-dropdown>button{padding:9px;text-align:left;background:#fff;border:0;border-radius:3px;color:#264057;cursor:pointer;font-size:12px}
.sales-print-dropdown>button:hover{background:#eaf4fa}
.sales-print-dropdown>span{font-size:11px;font-weight:700}
.sales-print-dropdown>label{font-size:12px}
.sales-print-dropdown>hr{width:100%;border:0;border-top:1px solid #dce5ec}
#sales-print-error{color:#ad3427;font-size:11px;margin:2px 0}
</style>
@endpush
@push('scripts')
<script>
(()=>{
const menu=document.getElementById('sales-print-dialog'),button=document.getElementById('sales-print-open'),error=document.getElementById('sales-print-error');
if(!menu||!button)return;
const close=()=>{menu.hidden=true;button.setAttribute('aria-expanded','false')};
button.addEventListener('click',e=>{e.stopPropagation();menu.hidden=!menu.hidden;button.setAttribute('aria-expanded',String(!menu.hidden));error.hidden=true});
menu.addEventListener('click',e=>e.stopPropagation());
document.addEventListener('click',close);
document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});
const format=()=>menu.querySelector('input[name="sales-print-format"]:checked')?.value||'a4';
const fail=msg=>{error.textContent=msg;error.hidden=false};
document.getElementById('sales-print-one').addEventListener('click',()=>{
const rows=[...document.querySelectorAll('#sales-live-results tbody input[data-row-select]:checked')];
if(rows.length!==1){fail('Marque exatamente uma venda na tabela.');return}
const link=rows[0].closest('tr')?.querySelector('a[href*="/receipt"]');
if(!link){fail('A linha marcada deve ser uma venda.');return}
const url=new URL(link.href,location.href);
if(format()==='a4')url.searchParams.set('format','a4');
window.open(url.toString(),'_blank','noopener');close();
});
document.getElementById('sales-print-list').addEventListener('click',()=>{
const table=document.querySelector('#sales-live-results table');
if(!table){fail('Listagem indisponível.');return}
const clone=table.cloneNode(true);
clone.querySelectorAll('th:first-child,td:first-child,th:last-child,td:last-child').forEach(el=>el.remove());
const popup=window.open('','_blank');
if(!popup){fail('Permita pop-ups para imprimir.');return}
const thermal=format()==='80';
const css='@page{size:'+(thermal?'80mm auto':'A4 portrait')+';margin:'+(thermal?'3mm':'12mm')+'}body{font:12px Arial;color:#111}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #ccc;padding:5px;text-align:left}'+(thermal?'body{font-size:9px}th,td{font-size:8px;padding:3px 1px;overflow-wrap:anywhere}':'');
popup.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Listagem</title><style>'+css+'</style></head><body><h2>Listagem de vendas</h2>'+clone.outerHTML+'</body></html>');
popup.document.close();popup.focus();popup.print();close();
});
})();
</script>
@endpush
@endsection
