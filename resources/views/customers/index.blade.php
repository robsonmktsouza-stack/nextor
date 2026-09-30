@extends('layouts.app')
@section('titleMeta'){{ $customers->total() }} {{ $customers->total() === 1 ? 'registro' : 'registros' }}@endsection
@section('title','Clientes')
@section('description','Mantenha os dados dos compradores organizados para registrar vendas.')
@section('content')
<section class="cms-card">
<div class="grid-actionbar">
  <div class="grid-actions-left">
    <a class="grid-primary-action" href="{{ route('customers.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Novo</span></a>
    <div class="grid-tool-group">
      <button class="grid-tool grid-tool-wide" type="button" data-bulk-submit="customers-duplicate" disabled data-tooltip="Duplicar selecionados">@include('partials.icon',['name'=>'copy','size'=>18])<span>Duplicar</span></button>
      <button class="grid-tool grid-tool-danger" type="button" data-bulk-submit="customers-delete" data-confirm="Excluir os clientes selecionados? Clientes com vendas vinculadas serão preservados." disabled data-tooltip="Excluir selecionados">@include('partials.icon',['name'=>'trash','size'=>18])</button>
      <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
      <button class="grid-tool" type="button" data-export-table="clientes.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
      <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
    </div>
    <div class="bulk-actions">
      <select class="bulk-action-select" data-bulk-menu disabled aria-label="Ações em massa">
        <option value="">Ações em massa</option>
        <option value="customers-duplicate">Duplicar selecionados</option>
        <option value="local:export">Exportar selecionados</option>
        <option value="local:print">Imprimir selecionados</option>
        <option value="customers-delete" data-confirm="Excluir os clientes selecionados? Clientes com vendas vinculadas serão preservados.">Excluir selecionados</option>
      </select>
      <button class="bulk-apply" type="button" data-bulk-apply disabled>Aplicar</button>
    </div>
    <span class="selection-count" data-selection-count hidden></span>
    <form id="customers-duplicate" method="post" action="{{ route('customers.bulk-duplicate') }}" hidden>@csrf</form>
    <form id="customers-delete" method="post" action="{{ route('customers.bulk-delete') }}" hidden>@csrf @method('DELETE')</form>
  </div>
  <div class="grid-actions-right">
    <button class="grid-filter-button" type="button" data-filter-toggle="customer-filters" data-tooltip="Filtro avançado" aria-label="Filtro avançado">@include('partials.icon',['name'=>'search','size'=>18])</button>
  </div>
</div>
<div class="grid-filter-panel" id="customer-filters" @if(!$term) hidden @endif>
<form class="toolbar-filters" method="get"><div class="table-search-group"><input name="search" value="{{ $term }}" placeholder="Buscar cliente ou CPF/CNPJ..." aria-label="Buscar clientes"><button type="submit" class="table-search-submit" data-tooltip="Pesquisar" aria-label="Pesquisar">@include('partials.icon',['name'=>'search','size'=>17])</button></div>@if($term)<a class="btn btn-light" href="{{ route('customers.index') }}">Limpar</a>@endif</form>
</div>
<div class="table-scroll"><table class="cms-table"><thead><tr><th class="select-cell"><input type="checkbox" data-check-all aria-label="Selecionar todos"></th><th>Cód.</th><th>Nome</th><th>Documento</th><th>Fone</th><th>Palavras-chave</th><th>Cidade/UF</th><th class="action-cell">Ações</th></tr></thead><tbody>
@forelse($customers as $customer)<tr>
<td class="select-cell"><input type="checkbox" data-row-select value="{{ $customer->id }}" aria-label="Selecionar {{ $customer->name }}"></td>
<td class="nowrap">{{ $customer->id }}</td>
<td><strong class="table-title">{{ $customer->name }}</strong>@if($customer->trade_name)<small class="table-subtitle">{{ $customer->trade_name }}</small>@endif</td>
<td>{{ $customer->document ?: '—' }}</td>
<td>{{ $customer->phone ?: '—' }}</td>
<td>{{ $customer->keywords ?: '—' }}</td>
<td>{{ $customer->city ? $customer->city.($customer->state ? '/'.$customer->state : '') : '—' }}</td>
<td class="action-cell"><div class="row-actions"><a class="btn-icon" href="{{ route('customers.edit',$customer) }}" data-tooltip="Editar cliente" aria-label="Editar cliente {{ $customer->name }}">@include('partials.icon',['name'=>'edit','size'=>16])</a></div></td>
</tr>
@empty<tr><td class="empty-cell" colspan="8">Nenhum cliente encontrado.</td></tr>@endforelse
</tbody></table></div><div class="table-footerbar"><span>Exibindo {{ $customers->firstItem() ?? 0 }}–{{ $customers->lastItem() ?? 0 }} de {{ $customers->total() }}</span><div class="card-pagination">{{ $customers->links('partials.pagination') }}</div></div></section>
@endsection
