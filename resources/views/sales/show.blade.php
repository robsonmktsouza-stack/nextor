@extends('layouts.app')
@section('title',($sale->operation_type==='quote'?'Orçamento ':'Venda ').'#'.str_pad($sale->id,5,'0',STR_PAD_LEFT))
@section('actions')
<a class="btn btn-secondary" href="{{ route('sales.index') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
@if(auth()->user()->canAccess('returns') && $sale->operation_type==='sale' && $sale->status==='completed')
<a class="btn btn-primary" href="{{ route('sales.returns.create',['sale'=>$sale->id]) }}">@include('partials.icon',['name'=>'return','size'=>16]) Nova devolução</a>
@endif
@if($nfceDocument?->status==='authorized'
    && (auth()->user()->canAccess('fiscal') || auth()->user()->canAccess('pdv'))
    && (str_ends_with((string)$nfceDocument->xml_path,'authorized.xml')
        || str_ends_with((string)$nfceDocument->xml_path,'authorized-recovered.xml')))
<a class="btn btn-success" target="_blank" rel="noopener noreferrer"
   href="{{ route(auth()->user()->canAccess('fiscal') ? 'fiscal.nfce.danfe' : 'pdv.nfce.danfe',$nfceDocument) }}">
  @include('partials.icon',['name'=>'print','size'=>16]) Imprimir DANFE NFC-e
</a>
@endif
@if($sale->status==='completed')
<button class="btn btn-danger-outline" data-dialog-open="sale-cancel" type="button">@include('partials.icon',['name'=>'x','size'=>16]) Cancelar</button>
@endif
@endsection

@section('content')
@include('sales._nav')
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
            <td>{{ $payment->due_date?->format(\App\Models\AppSetting::dateFormat()) ?? '—' }}</td>
            <td>{{ $paymentLabels[$payment->payment_method] ?? ($payment->payment_method ?: 'Não informado') }}</td>
            <td>{{ $payment->receivable?'A receber':'Recebido' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endif

    @if(auth()->user()->canAccess('returns') && $saleReturns->isNotEmpty())
    <div class="card-header">
      <div><h2>Devoluções</h2><p>{{ $saleReturns->count() }} registro(s) vinculados a esta venda</p></div>
      <a class="minor-link" href="{{ route('sales.returns.index',['search'=>$sale->id]) }}">Ver todas</a>
    </div>
    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Cód.</th><th>Data</th><th>Itens</th><th>Valor</th><th>Situação</th><th class="action-cell"></th></tr></thead>
        <tbody>
        @foreach($saleReturns as $return)
          <tr>
            <td><a class="table-link" href="{{ route('sales.returns.show',$return) }}">#{{ str_pad((string)$return->id,5,'0',STR_PAD_LEFT) }}</a></td>
            <td>{{ $return->return_date->format(\App\Models\AppSetting::dateFormat()) }}</td>
            <td>{{ $return->items_count }}</td>
            <td class="price-strong">R$ {{ number_format((float)$return->total,2,',','.') }}</td>
            <td><span class="status {{ $return->status==='completed'?'status-ok':'status-muted' }}">{{ $return->status==='completed'?'Concluída':'Cancelada' }}</span></td>
            <td class="action-cell"><a class="btn-icon" href="{{ route('sales.returns.show',$return) }}" data-tooltip="Abrir devolução">@include('partials.icon',['name'=>'chevron','size'=>16])</a></td>
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
      <div><span>Data</span><strong>{{ ($sale->operation_date ?? $sale->created_at)->format(\App\Models\AppSetting::dateFormat()) }}</strong></div>
      @if($sale->operation_type==='quote' && $sale->quote_expires_at)
        <div><span>Validade</span><strong>{{ $sale->quote_expires_at->format(\App\Models\AppSetting::dateFormat()) }}</strong></div>
      @endif
      <div><span>Consumidor final</span><strong>{{ $sale->final_consumer?'Sim':'Não' }}</strong></div>
      @if($sale->keyword)<div><span>Palavra-chave</span><strong>{{ $sale->keyword }}</strong></div>@endif
      <div><span>Responsável</span><strong>{{ $sale->user?->name ?? '—' }}</strong></div>
      <div><span>Status</span><strong>{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</strong></div>
      @if($sale->cancelled_at)<div><span>Cancelada em</span><strong>{{ $sale->cancelled_at->format(\App\Models\AppSetting::dateFormat().' H:i') }}</strong></div>@endif
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
