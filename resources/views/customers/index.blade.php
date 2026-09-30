@extends('layouts.app')
@section('title','Clientes')
@section('description','Mantenha os dados dos compradores organizados para registrar vendas.')
@section('content')
<section class="cms-card">
<div class="grid-actionbar">
  <div class="grid-actions-left">
    <button class="grid-primary-action" type="button" data-dialog-open="customer-create">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Novo</span></button>
    <div class="grid-tool-group">
      <button class="grid-tool" type="button" data-print-page title="Imprimir" aria-label="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
    </div>
  </div>
  <div class="grid-actions-right">
    <button class="grid-filter-button" type="button" data-filter-toggle="customer-filters" title="Filtro avançado" aria-label="Filtro avançado">@include('partials.icon',['name'=>'search','size'=>18])</button>
  </div>
</div>
<div class="grid-filter-panel" id="customer-filters" @if(!$term) hidden @endif>
<form class="toolbar-filters" method="get"><label class="table-search">@include('partials.icon',['name'=>'search','size'=>16])<input name="search" value="{{ $term }}" placeholder="Buscar cliente ou CPF/CNPJ..."></label><button class="btn btn-secondary">Pesquisar</button>@if($term)<a class="btn btn-light" href="{{ route('customers.index') }}">Limpar</a>@endif</form>
</div>
<div class="table-scroll"><table class="cms-table"><thead><tr><th>Cliente</th><th>Documento</th><th>E-mail</th><th>Telefone</th><th class="action-cell">Ações</th></tr></thead><tbody>
@forelse($customers as $customer)<tr><td><strong class="table-title">{{ $customer->name }}</strong></td><td>{{ $customer->document ?: '—' }}</td><td>{{ $customer->email ?: '—' }}</td><td>{{ $customer->phone ?: '—' }}</td><td class="action-cell"><button type="button" class="btn-icon" data-dialog-open="customer-edit-{{ $customer->id }}" title="Editar cliente">@include('partials.icon',['name'=>'edit','size'=>16])</button></td></tr>
@empty<tr><td class="empty-cell" colspan="5">Nenhum cliente encontrado.</td></tr>@endforelse
</tbody></table></div><div class="card-pagination">{{ $customers->links('partials.pagination') }}</div></section>
<dialog class="erp-dialog" id="customer-create"><form action="{{ route('customers.store') }}" method="post">@csrf
<div class="dialog-header"><div><h2>Novo cliente</h2><p>Dados cadastrais</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
<div class="dialog-body"><div class="form-grid">
<label class="field wide"><span>Nome / razão social *</span><input name="name" value="{{ old('name') }}" required></label>
<label class="field"><span>CPF/CNPJ</span><input name="document" value="{{ old('document') }}" maxlength="20"></label>
<label class="field"><span>Telefone</span><input name="phone" value="{{ old('phone') }}" maxlength="25"></label>
<label class="field wide"><span>E-mail</span><input name="email" type="email" value="{{ old('email') }}"></label>
<label class="field wide"><span>Observações</span><textarea name="notes" rows="3">{{ old('notes') }}</textarea></label>
</div></div><div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button class="btn btn-primary">Salvar cliente</button></div></form></dialog>
@foreach($customers as $customer)
<dialog class="erp-dialog" id="customer-edit-{{ $customer->id }}"><form action="{{ route('customers.update',$customer) }}" method="post">@csrf @method('PUT')
<div class="dialog-header"><div><h2>Editar cliente</h2><p>{{ $customer->name }}</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
<div class="dialog-body"><div class="form-grid">
<label class="field wide"><span>Nome / razão social *</span><input name="name" value="{{ $customer->name }}" required></label>
<label class="field"><span>CPF/CNPJ</span><input name="document" value="{{ $customer->document }}" maxlength="20"></label>
<label class="field"><span>Telefone</span><input name="phone" value="{{ $customer->phone }}"></label>
<label class="field wide"><span>E-mail</span><input name="email" type="email" value="{{ $customer->email }}"></label>
<label class="field wide"><span>Observações</span><textarea name="notes" rows="3">{{ $customer->notes }}</textarea></label>
</div></div><div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button class="btn btn-primary">Salvar alterações</button></div></form></dialog>
@endforeach
@if($errors->any())@push('scripts')<script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('customer-create')?.showModal());</script>@endpush
@endif
@endsection
