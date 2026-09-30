@extends('layouts.app')
@section('title','Nova venda')
@section('description','Selecione os produtos e quantidades. Os preços e o saldo serão confirmados pelo servidor.')
@section('actions')<a class="btn btn-secondary" href="{{ route('sales.index') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar às vendas</a>@endsection
@section('content')
<form id="sale-form" action="{{ route('sales.store') }}" method="post">@csrf
<div class="sale-form-layout">
 <div class="sale-form-main">
  <section class="cms-card"><div class="card-header"><div><h2>Identificação</h2><p>Dados gerais da operação</p></div></div>
   <div class="card-content form-grid"><label class="field wide"><span>Cliente</span><select name="customer_id"><option value="">Consumidor não identificado</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id')==$customer->id)>{{ $customer->name }}{{ $customer->document?' — '.$customer->document:'' }}</option>@endforeach</select></label>
   <label class="field wide"><span>Observações internas</span><textarea name="notes" rows="2" placeholder="Observações opcionais...">{{ old('notes') }}</textarea></label></div></section>
  <section class="cms-card"><div class="card-header"><div><h2>Itens da venda</h2><p>Adicione um ou mais produtos</p></div><button class="btn btn-secondary" type="button" id="addSaleItem">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar item</button></div>
  <div class="table-scroll"><table class="cms-table sale-items-table"><thead><tr><th style="width:45%">Produto</th><th>Quantidade</th><th>Preço unitário</th><th>Subtotal</th><th></th></tr></thead><tbody id="saleItems"></tbody></table></div>
  <div class="sale-help">O preço será confirmado no momento do registro e o estoque será atualizado automaticamente.</div>
  </section>
 </div>
 <aside class="sale-sidebar"><section class="cms-card sale-summary"><div class="card-header"><div><h2>Resumo da venda</h2><p>Valores calculados</p></div></div><div class="summary-body"><div><span>Itens</span><strong id="summaryItems">0</strong></div><div><span>Unidades</span><strong id="summaryQty">0</strong></div><div class="summary-total"><span>Total da venda</span><strong id="summaryTotal">R$ 0,00</strong></div></div><div class="summary-actions"><button id="submitSale" type="submit" class="btn btn-primary btn-block">@include('partials.icon',['name'=>'check','size'=>17]) Concluir venda</button><a class="btn btn-secondary btn-block" href="{{ route('sales.index') }}">Cancelar</a></div></section>
 <div class="hint-card">@include('partials.icon',['name'=>'shield','size'=>19])<span>O ERP impede a conclusão de vendas com estoque insuficiente.</span></div>
 </aside>
</div>
</form>
<script type="application/json" id="sale-products">@json($products)</script>
<script type="application/json" id="sale-old-items">@json(old('items',[]))</script>
@endsection
