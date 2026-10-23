@extends('layouts.app')
@section('title',$editing?'Editar compra #'.$purchase->id:'Nova compra')
@section('content')
<form method="post" id="purchaseForm" class="purchase-editor" action="{{ $editing?route('purchases.update',$purchase):route('purchases.store') }}">
  @csrf
  @if($editing) @method('PUT') @endif
  <section class="editor-panel purchase-header-panel">
    <div class="purchase-section-heading"><h2>Dados da compra</h2></div>
    <div class="purchase-head-grid">
      <label class="field purchase-col-5"><span>Fornecedor *</span>
        <select name="supplier_id" data-quick-create="suppliers" required autofocus>
          <option value="">Selecione um fornecedor</option>
          @foreach($suppliers as $supplier)
            <option value="{{ $supplier->id }}" @selected((int)old('supplier_id',$purchase->supplier_id)===$supplier->id)>{{ $supplier->name }}{{ $supplier->document?' — '.$supplier->document:'' }}</option>
          @endforeach
        </select>
      </label>
      <div class="field purchase-col-2 purchase-supplier-link"><span>Cadastro</span><button type="button" class="btn btn-secondary" data-quick-supplier>@include('partials.icon',['name'=>'plus','size'=>15]) Novo fornecedor</button></div>
      <label class="field purchase-col-2"><span>Data da compra *</span><input type="date" name="operation_date" value="{{ old('operation_date',$purchase->operation_date?->format('Y-m-d')) }}" required></label>
      <label class="field purchase-col-3"><span>Número da nota / pedido</span><input name="document_number" maxlength="80" placeholder="Nº do documento" value="{{ old('document_number',$purchase->document_number) }}"></label>

      <label class="field purchase-col-5"><span>NF-e recebida vinculada</span>
        <select name="incoming_nfe_id">
          <option value="">Sem vínculo fiscal</option>
          @foreach($invoices as $invoice)
            <option value="{{ $invoice->id }}" @selected((int)old('incoming_nfe_id',$purchase->incoming_nfe_id)===$invoice->id)>{{ $invoice->issued_at?->format('d/m/Y') }} · {{ $invoice->supplier_name ?: 'Fornecedor' }} · R$ {{ number_format((float)$invoice->total,2,',','.') }} · ...{{ substr($invoice->access_key,-6) }}</option>
          @endforeach
        </select>
      </label>
      <div class="field purchase-col-2 purchase-supplier-link"><span>Importação fiscal</span>
        @if(auth()->user()->canAccess('fiscal'))
        <a class="btn btn-secondary" href="{{ route('fiscal.purchases.index') }}">@include('partials.icon',['name'=>'upload','size'=>15]) Recebidas / XML</a>
        @else
        <span class="purchase-muted">Acesso pelo Fiscal</span>
        @endif
      </div>
      <label class="field purchase-col-2"><span>Vencimento</span><input name="due_date" type="date" value="{{ old('due_date',$purchase->due_date?->format('Y-m-d')) }}" data-purchase-due></label>
      <label class="field purchase-col-3"><span>Palavra-chave</span><input name="keyword" maxlength="190" value="{{ old('keyword',$purchase->keyword) }}" placeholder="Ex.: reposição mensal"></label>
    </div>
  </section>

  <section class="editor-panel purchase-items-panel">
    <div class="purchase-section-heading">
      <h2>Produtos</h2>
      <button type="button" class="btn btn-secondary" data-purchase-add>@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar produto</button>
    </div>
    <div class="purchase-items" data-purchase-items aria-label="Itens da compra"></div>
    <div class="purchase-empty-note" data-purchase-empty hidden>Inclua ao menos um produto na compra.</div>

    <div class="purchase-bottom-grid">
      <label class="field"><span>Frete</span><input type="number" name="freight" min="0" step="0.01" value="{{ old('freight',$purchase->freight??'0.00') }}" data-number-kind="money" data-purchase-extra required></label>
      <label class="field"><span>Outras despesas</span><input type="number" name="other_expenses" min="0" step="0.01" value="{{ old('other_expenses',$purchase->other_expenses??'0.00') }}" data-number-kind="money" data-purchase-extra required></label>
      <label class="field"><span>Desconto geral</span><input type="number" name="discount" min="0" step="0.01" value="{{ old('discount',$purchase->discount??'0.00') }}" data-number-kind="money" data-purchase-extra required></label>
      <div class="purchase-sum"><span>Subtotal dos itens</span><strong data-purchase-subtotal>R$ 0,00</strong></div>
      <div class="purchase-sum purchase-sum-total"><span>Total da compra</span><strong data-purchase-total>R$ 0,00</strong></div>
    </div>
    <p class="purchase-validation-error" role="alert" data-purchase-error hidden></p>
  </section>

  <section class="editor-panel purchase-extras-panel">
    <div class="purchase-extra-grid">
      <div class="field"><span>Gerar conta a pagar ao receber?</span>
        <label class="switch-field">
          <input type="hidden" name="generate_payable" value="0">
          <input type="checkbox" name="generate_payable" value="1" data-purchase-payable
            @checked(old('generate_payable',$purchase->generate_payable??true)) @disabled(!$hasFinance)>
          <span class="switch-track"></span>
          <strong data-switch-label>{{ $hasFinance?'Sim':'Financeiro indisponível' }}</strong>
        </label>
      </div>
      <label class="field"><span>Observações gerais</span><textarea name="notes" rows="2" maxlength="5000" placeholder="Informações internas desta compra">{{ old('notes',$purchase->notes) }}</textarea></label>
    </div>
  </section>
  <div class="editor-savebar purchase-savebar">
    <div class="purchase-save-total"><span>Valor total</span><strong data-purchase-total>R$ 0,00</strong></div>
    <button type="submit" class="btn btn-success">@include('partials.icon',['name'=>'check','size'=>16]) Salvar rascunho</button>
    <a class="btn btn-secondary" href="{{ $editing?route('purchases.show',$purchase):route('purchases.index') }}">Cancelar</a>
  </div>
</form>
@php
$previousPurchaseItems=$editing
    ? $purchase->items->map(fn($item)=>[
        'product_id'=>$item->product_id,
        'quantity'=>$item->quantity,
        'unit_cost'=>$item->unit_cost,
        'discount'=>$item->discount,
        'other_costs'=>$item->other_costs,
        'external_costs'=>$item->external_costs,
        'ipi'=>$item->ipi,
        'icms_st'=>$item->icms_st,
      ])->all()
    : [];
$previousPurchaseItems=old('items',$previousPurchaseItems);
@endphp
<script id="purchaseProductsJson" type="application/json">@json($products)</script>
<script id="purchaseItemsJson" type="application/json">@json($previousPurchaseItems)</script>
@push('styles')
<link rel="stylesheet" href="{{ asset('css/purchases.css') }}?v={{ filemtime(public_path('css/purchases.css')) }}">
@endpush
@push('scripts')
<script defer src="{{ asset('js/purchases.js') }}?v={{ filemtime(public_path('js/purchases.js')) }}"></script>
@endpush
@endsection
