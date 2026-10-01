@extends('layouts.app')
@section('title',($sale->operation_type==='quote'?'Orçamento ':'Venda ').'#'.str_pad($sale->id,5,'0',STR_PAD_LEFT))
@section('actions')
<a class="btn btn-secondary" href="{{ route('sales.index') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
@if($sale->status==='completed')
<button class="btn btn-danger-outline" data-dialog-open="sale-cancel" type="button">@include('partials.icon',['name'=>'x','size'=>16]) Cancelar</button>
@endif
@endsection

@section('content')
<div class="detail-grid">
  <section class="cms-card">
    <div class="card-header">
      <div>
        <h2>Itens da operação</h2>
        <p>{{ $sale->items->count() }} item(ns)</p>
      </div>
      <span class="status {{ $sale->status==='completed'?'status-ok':'status-muted' }}">{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</span>
    </div>

    <div class="table-scroll">
      <table class="cms-table">
        <thead>
          <tr><th>Tipo</th><th>Código</th><th>Item</th><th>Quantidade</th><th>Preço unit.</th><th>Desconto</th><th>Total</th></tr>
        </thead>
        <tbody>
        @foreach($sale->items as $item)
          <tr>
            <td>{{ match($item->item_type){'service'=>'Serviço','freight'=>'Frete','expense'=>'Despesa',default=>'Produto'} }}</td>
            <td><span class="code-tag">{{ $item->product_sku ?: '—' }}</span></td>
            <td>
              <strong class="table-title">{{ $item->product_name }}</strong>
              @if($item->notes)<small class="table-subtitle">{{ $item->notes }}</small>@endif
            </td>
            <td>{{ number_format((float)$item->quantity,3,',','.') }}</td>
            <td>R$ {{ number_format((float)$item->unit_price,2,',','.') }}</td>
            <td>R$ {{ number_format((float)$item->discount,2,',','.') }}</td>
            <td class="price-strong">R$ {{ number_format((float)$item->line_total,2,',','.') }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>

    <div class="detail-total">
      <span>Subtotal R$ {{ number_format((float)$sale->subtotal,2,',','.') }}</span>
      @if((float)$sale->discount_total>0)<span>Descontos R$ {{ number_format((float)$sale->discount_total,2,',','.') }}</span>@endif
      Total <strong>R$ {{ number_format((float)$sale->total,2,',','.') }}</strong>
    </div>

    @if($sale->payments->isNotEmpty())
    <div class="card-header"><div><h2>Financeiro</h2><p>{{ $sale->payments->count() }} parcela(s)</p></div></div>
    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Parcela</th><th>Valor</th><th>Vencimento</th><th>Forma</th><th>Situação</th></tr></thead>
        <tbody>
        @foreach($sale->payments as $payment)
          <tr>
            <td>{{ $payment->installment }}/{{ $sale->payments->count() }}</td>
            <td class="price-strong">R$ {{ number_format((float)$payment->amount,2,',','.') }}</td>
            <td>{{ $payment->due_date?->format('d/m/Y') ?? '—' }}</td>
            <td>{{ match($payment->payment_method){'cash'=>'Dinheiro','pix'=>'PIX','debit_card'=>'Cartão de débito','credit_card'=>'Cartão de crédito','bank_slip'=>'Boleto','bank_transfer'=>'Transferência','other'=>'Outro',default=>'Não informado'} }}</td>
            <td>{{ $payment->receivable?'A receber':'Recebido' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endif
  </section>

  <aside class="cms-card">
    <div class="card-header"><div><h2>Dados da operação</h2><p>Informações de registro</p></div></div>
    <div class="detail-meta">
      <div><span>Tipo</span><strong>{{ $sale->operation_type==='quote'?'Orçamento':'Venda' }}</strong></div>
      <div><span>Cliente</span><strong>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</strong></div>
      <div><span>Data</span><strong>{{ ($sale->operation_date ?? $sale->created_at)->format('d/m/Y') }}</strong></div>
      <div><span>Consumidor final</span><strong>{{ $sale->final_consumer?'Sim':'Não' }}</strong></div>
      @if($sale->keyword)<div><span>Palavra-chave</span><strong>{{ $sale->keyword }}</strong></div>@endif
      <div><span>Responsável</span><strong>{{ $sale->user?->name ?? '—' }}</strong></div>
      <div><span>Status</span><strong>{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</strong></div>
      @if($sale->cancelled_at)<div><span>Cancelada em</span><strong>{{ $sale->cancelled_at->format('d/m/Y H:i') }}</strong></div>@endif
      @if($sale->notes)<div><span>Observações</span><strong>{{ $sale->notes }}</strong></div>@endif
    </div>
  </aside>
</div>

@if($sale->status==='completed')
<dialog class="erp-dialog small-dialog" id="sale-cancel">
  <form action="{{ route('sales.cancel',$sale) }}" method="post">@csrf
    <div class="dialog-header">
      <div><h2>Cancelar {{ $sale->operation_type==='quote'?'orçamento':'venda' }} #{{ $sale->id }}?</h2><p>Confirmação da operação</p></div>
      <button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button>
    </div>
    <div class="dialog-body">
      <p>{{ $sale->operation_type==='sale' ? 'Os produtos controlados serão devolvidos ao estoque e o estorno ficará registrado.' : 'O orçamento será marcado como cancelado.' }}</p>
    </div>
    <div class="dialog-footer">
      <button type="button" data-dialog-close class="btn btn-secondary">Voltar</button>
      <button class="btn btn-danger">Confirmar cancelamento</button>
    </div>
  </form>
</dialog>
@endif
@endsection
