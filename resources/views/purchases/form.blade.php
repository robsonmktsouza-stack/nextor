@extends('layouts.app')
@section('title',$editing?'Editar compra #'.$purchase->id:'Nova compra')
@section('content')
<form method="post" id="purchaseForm" class="purchase-editor" action="{{ $editing?route('purchases.update',$purchase):route('purchases.store') }}">
  @csrf
  @if($editing)@method('PUT')@endif
  <section class="editor-panel">
    <div class="editor-grid cols-12">
      <label class="field col-6"><span>Fornecedor *</span>
        <select name="supplier_id" required autofocus>
          <option value="">Selecione um fornecedor</option>
          @foreach($suppliers as $supplier)
            <option value="{{ $supplier->id }}" @selected((int)old('supplier_id',$purchase->supplier_id)===$supplier->id)>{{ $supplier->name }}{{ $supplier->document?' — '.$supplier->document:'' }}</option>
          @endforeach
        </select>
      </label>
      <div class="field col-3 purchase-supplier-link"><span>Cadastro</span><a href="{{ route('customers.create') }}" class="btn btn-secondary">@include('partials.icon',['name'=>'plus','size'=>15]) Cadastrar fornecedor</a></div>
      <label class="field col-3"><span>Data da compra *</span><input name="operation_date" type="date" required value="{{ old('operation_date',$purchase->operation_date?->format('Y-m-d')) }}"></label>
      <label class="field col-3"><span>Número do documento</span><input name="document_number" maxlength="80" value="{{ old('document_number',$purchase->document_number) }}" placeholder="Pedido ou nota"></label>
      <label class="field col-6"><span>NF-e recebida vinculada</span><select name="incoming_nfe_id">
        <option value="">Sem vínculo fiscal</option>
        @foreach($invoices as $invoice)
        <option value="{{ $invoice->id }}" @selected((int)old('incoming_nfe_id',$purchase->incoming_nfe_id)===$invoice->id)>{{ $invoice->issued_at?->format('d/m/Y') }} · {{ $invoice->supplier_name ?: 'Fornecedor' }} · R$ {{ number_format((float)$invoice->total,2,',','.') }} · ...{{ substr($invoice->access_key,-6) }}</option>
        @endforeach
      </select></label>
      <label class="field col-3"><span>Vencimento</span><input name="due_date" type="date" value="{{ old('due_date',$purchase->due_date?->format('Y-m-d')) }}"></label>
    </div>
  </section>
  <section class="editor-panel">
    <div class="purchase-section-heading">
      <h2>Itens da compra</h2>
      <button type="button" class="btn btn-secondary" data-purchase-add>@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar produto</button>
    </div>
    <div class="table-scroll">
      <table class="cms-table purchase-items-table">
        <thead><tr><th>Produto *</th><th>Quantidade *</th><th>Custo unitário *</th><th>Subtotal</th><th class="action-cell">Remover</th></tr></thead>
        <tbody data-purchase-items></tbody>
      </table>
    </div>
    <div class="editor-grid cols-12 purchase-totals">
      <label class="field col-3"><span>Frete (R$)</span><input name="freight" type="number" min="0" step="0.01" value="{{ old('freight',$purchase->freight??'0.00') }}" data-purchase-money required></label>
      <label class="field col-3"><span>Desconto (R$)</span><input name="discount" type="number" min="0" step="0.01" value="{{ old('discount',$purchase->discount??'0.00') }}" data-purchase-money required></label>
      <div class="field col-3 purchase-sum"><span>Subtotal dos itens</span><strong data-purchase-subtotal>R$ 0,00</strong></div>
      <div class="field col-3 purchase-sum"><span>Valor total</span><strong data-purchase-total>R$ 0,00</strong></div>
    </div>
  </section>
  <section class="editor-panel">
    <div class="editor-grid cols-12">
      <div class="field col-4"><span>Gerar conta a pagar ao receber?</span>
        <label class="switch-field">
          <input type="hidden" name="generate_payable" value="0">
          <input type="checkbox" name="generate_payable" value="1"
            @checked(old('generate_payable',$purchase->generate_payable??true)) @disabled(!$hasFinance)>
          <span class="switch-track"></span>
          <strong data-switch-label>{{ $hasFinance?'Sim':'Financeiro indisponível' }}</strong>
        </label>
      </div>
      <label class="field col-8"><span>Observações</span><textarea name="notes" rows="2" maxlength="5000">{{ old('notes',$purchase->notes) }}</textarea></label>
    </div>
  </section>
  <div class="editor-savebar purchase-savebar">
    <span class="purchase-save-total">Total <strong data-purchase-total>R$ 0,00</strong></span>
    <button type="submit" class="btn btn-success">@include('partials.icon',['name'=>'check','size'=>16]) Salvar rascunho</button>
    <a href="{{ $editing?route('purchases.show',$purchase):route('purchases.index') }}" class="btn btn-secondary">Cancelar</a>
  </div>
</form>
<script id="purchaseProductsJson" type="application/json">@json($products)</script>
@php
$previousPurchaseItems=$editing
    ? $purchase->items->map(fn($item)=>[
        'product_id'=>$item->product_id,
        'quantity'=>$item->quantity,
        'unit_cost'=>$item->unit_cost,
      ])->all()
    : [];
$previousPurchaseItems=old('items',$previousPurchaseItems);
@endphp
<script id="purchaseItemsJson" type="application/json">@json($previousPurchaseItems)</script>
@push('styles')<link rel="stylesheet" href="{{ asset('css/purchases.css') }}?v={{ filemtime(public_path('css/purchases.css')) }}">@endpush
@push('scripts')<script defer src="{{ asset('js/purchases.js') }}?v={{ filemtime(public_path('js/purchases.js')) }}"></script>@endpush
@endsection
