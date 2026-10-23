@extends('layouts.app')
@section('title','Nova venda / orçamento')

@section('content')
@include('sales._nav')
<form id="sale-form" class="sale-editor" action="{{ route('sales.store') }}" method="post">
@csrf

<section class="sale-order-head editor-panel">
  <div class="editor-grid cols-12">
    <label class="field col-6">
      <span>Nome do cliente</span>
      <select name="customer_id" id="saleCustomer" data-quick-create="customers">
        <option value="">Consumidor não identificado</option>
        @foreach($customers as $customer)
          <option value="{{ $customer->id }}"
                  data-final-consumer="{{ $customer->final_consumer ? '1' : '0' }}"
                  @selected(old('customer_id')==$customer->id)>
            {{ $customer->name }}{{ $customer->document ? ' — '.$customer->document : '' }}
          </option>
        @endforeach
      </select>
    </label>

    <label class="field col-3">
      <span>Venda ou orçamento?</span>
      <select name="operation_type" id="saleOperationType">
        <option value="sale" @selected(old('operation_type','sale')==='sale')>Venda</option>
        <option value="quote" @selected(old('operation_type')==='quote')>Orçamento</option>
      </select>
    </label>

    <label class="field col-3">
      <span>Data</span>
      <input type="date" name="operation_date" value="{{ old('operation_date',now()->format('Y-m-d')) }}" required>
    </label>
    <label class="field col-3" id="saleQuoteValidity" @if(old('operation_type','sale')!=='quote') hidden @endif>
      <span>Validade do orçamento</span>
      <input type="date" id="saleQuoteExpires" name="quote_expires_at" value="{{ old('quote_expires_at') }}">
    </label>

    <div class="field col-3">
      <span>Consumidor final?</span>
      <label class="switch-field sale-final-consumer">
        <input type="hidden" name="final_consumer" value="0">
        <input type="checkbox" name="final_consumer" id="saleFinalConsumer" value="1" @checked(old('final_consumer',$defaultFinalConsumer))>
        <span class="switch-track"></span>
        <strong data-switch-label>{{ old('final_consumer',$defaultFinalConsumer) ? 'Sim' : 'Não' }}</strong>
      </label>
    </div>

    <label class="field col-4">
      <span>Palavra-chave</span>
      <div class="input-icon-group">
        <span class="input-icon">@include('partials.icon',['name'=>'tag','size'=>17])</span>
        <input name="keyword" value="{{ old('keyword') }}" maxlength="190">
      </div>
    </label>
  </div>
</section>

<section class="sale-cart-section">
  <div class="sale-section-title">
    <div>
      <h2>Carrinho</h2>
      
    </div>
    <button type="button" class="btn btn-secondary" id="addSaleItem">
      @include('partials.icon',['name'=>'plus','size'=>16]) Adicionar item
    </button>
  </div>

  <div class="sale-cart-table-wrap">
    <table class="sale-cart-table">
      <thead>
        <tr>
          <th>Produto ou serviço</th>
          <th>Preço de venda</th>
          <th>Quantidade</th>
          <th>Desconto</th>
          <th>Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="saleItems"></tbody>
      <tfoot>
        <tr>
          <td><strong>TOTAL</strong></td>
          <td></td>
          <td class="sale-total-qty" id="summaryQty">0</td>
          <td class="sale-total-discount" id="summaryDiscount">R$ 0,00</td>
          <td class="sale-total-value" id="summaryTotal">R$ 0,00</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <label class="field sale-general-notes">
    <span>Observações gerais</span>
    <textarea name="notes" rows="3">{{ old('notes') }}</textarea>
  </label>
</section>

<section class="sale-finance-section" id="saleFinanceSection" hidden>
  <div class="sale-section-title">
    <div>
      <h2>Financeiro</h2>
      
    </div>
  </div>

  <div class="sale-finance-table-wrap">
    <table class="sale-finance-table">
      <thead>
        <tr>
          <th>Parcela</th>
          <th>Valor</th>
          <th>Data de pagamento</th>
          <th>Forma de pagamento</th>
          <th>A receber</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="salePayments"></tbody>
      <tfoot>
        <tr>
          <td><strong>TOTAL</strong></td>
          <td><strong id="paymentsTotal">R$ 0,00</strong></td>
          <td colspan="4" class="payment-add-cell">
            <button type="button" class="btn btn-secondary" id="addSalePayment">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar parcela</button>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</section>

<div class="editor-savebar sale-savebar">
  <div class="sale-save-summary">
    <span id="saleOperationLabel">Venda</span>
    <strong id="saleSaveTotal">R$ 0,00</strong>
  </div>
  <button id="submitSale" type="submit" class="btn btn-success">@include('partials.icon',['name'=>'check','size'=>16]) Salvar</button>
  <button id="saveQuotePdf" type="submit" name="after_save" value="pdf" class="btn btn-primary" @if(old('operation_type','sale')!=='quote') hidden @endif>
    @include('partials.icon',['name'=>'print','size'=>16]) Salvar e visualizar PDF
  </button>
  <a class="btn btn-secondary" href="{{ route('sales.index') }}">Cancelar</a>
</div>
</form>

<script type="application/json" id="sale-products">@json($products)</script>
<script type="application/json" id="sale-services">@json($services)</script>
<script type="application/json" id="sale-old-items">@json(old('items',[]))</script>
<script type="application/json" id="sale-old-payments">@json(old('payments',[]))</script>
<script type="application/json" id="sale-payment-methods">@json($paymentMethods)</script>
<script type="application/json" id="sale-default-due-days">@json($defaultDueDays)</script>
@push('styles')
<style>
#saleQuoteValidity[hidden],#saveQuotePdf[hidden]{display:none!important}
</style>
@endpush
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const type=document.getElementById('saleOperationType');
  const validity=document.getElementById('saleQuoteValidity');
  const preview=document.getElementById('saveQuotePdf');
  if(!type || !validity || !preview)return;
  const update=()=>{
    const quote=type.value==='quote';
    validity.hidden=!quote;
    preview.hidden=!quote;
    validity.querySelector('input').disabled=!quote;
  };
  type.addEventListener('change',update);
  update();
});
</script>
@endpush
@endsection
