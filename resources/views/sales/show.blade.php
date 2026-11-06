@extends('layouts.app')
@section('title',($sale->operation_type==='quote'?'Orçamento ':'Venda ').'#'.str_pad($sale->id,5,'0',STR_PAD_LEFT))
@section('actions')
<a class="btn btn-secondary" href="{{ route('sales.index') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
@if($sale->operation_type==='quote')
<a class="btn btn-secondary" href="{{ route('sales.quote.pdf',$sale) }}" target="_blank" rel="noopener noreferrer">
  @include('partials.icon',['name'=>'print','size'=>16]) Visualizar PDF
</a>
<a class="btn btn-primary" href="{{ route('sales.quote.pdf',['sale'=>$sale,'download'=>1]) }}">
  @include('partials.icon',['name'=>'download','size'=>16]) Baixar PDF
</a>
@if($sale->status==='completed')
<button type="button" class="btn btn-success" data-dialog-open="sale-quote-convert">
  @include('partials.icon',['name'=>'check','size'=>16]) Converter em venda
</button>
@endif
@endif
@if($sale->operation_type==='sale')
<a class="btn btn-secondary" href="{{ route('sales.receipt',$sale) }}" target="_blank" rel="noopener noreferrer">
  @include('partials.icon',['name'=>'print','size'=>16]) Comprovante de venda
</a>
@endif
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
<div class="sale-editor sale-view-editor" aria-label="Formulário de visualização da operação">
  <section class="sale-order-head editor-panel">
    <div class="sale-section-title"><div><h2>Dados da operação</h2><p>Visualização da operação registrada</p></div><span class="status {{ $sale->status==='completed'?'status-ok':'status-muted' }}">{{ match($sale->status){'converted'=>'Convertido','cancelled'=>'Cancelado',default=>$sale->operation_type==='quote'?'Em aberto':'Concluída'} }}</span></div>
    <div class="editor-grid cols-12">
      <label class="field col-6"><span>Nome do cliente</span><input readonly value="{{ $sale->customer?->name ?? 'Consumidor não identificado' }}"></label>
      <label class="field col-3"><span>Venda ou orçamento?</span><input readonly value="{{ $sale->operation_type==='quote'?'Orçamento':'Venda' }}"></label>
      <label class="field col-3"><span>Data</span><input readonly value="{{ ($sale->operation_date ?? $sale->created_at)->format(\App\Models\AppSetting::dateFormat()) }}"></label>
      @if($sale->operation_type==='quote')
      <label class="field col-3"><span>Validade do orçamento</span><input readonly value="{{ $sale->quote_expires_at?->format(\App\Models\AppSetting::dateFormat()) ?? '—' }}"></label>
      @endif
      <label class="field col-3"><span>Consumidor final?</span><input readonly value="{{ $sale->final_consumer?'Sim':'Não' }}"></label>
      <label class="field col-4"><span>Palavra-chave</span><input readonly value="{{ $sale->keyword ?: '—' }}"></label>
      <label class="field col-3"><span>Responsável</span><input readonly value="{{ $sale->user?->name ?? '—' }}"></label>
    </div>
    @if($sale->operation_type==='quote' && $sale->convertedSale)
      <p>Venda gerada: <a href="{{ route('sales.show',$sale->convertedSale) }}">#{{ str_pad((string)$sale->convertedSale->id,5,'0',STR_PAD_LEFT) }}</a></p>
    @elseif($sale->sourceQuote)
      <p>Originada do orçamento: <a href="{{ route('sales.show',$sale->sourceQuote) }}">#{{ str_pad((string)$sale->sourceQuote->id,5,'0',STR_PAD_LEFT) }}</a></p>
    @endif
  </section>

  <section class="sale-cart-section">
    <div class="sale-section-title"><div><h2>Carrinho</h2><p>{{ $sale->items->count() }} item(ns)</p></div></div>
    <div class="sale-cart-table-wrap">
      <table class="sale-cart-table">
        <thead><tr><th>Produto ou serviço</th><th>Preço de venda</th><th>Quantidade</th><th>Desconto</th><th>Total</th></tr></thead>
        <tbody>
        @foreach($sale->items as $item)
          <tr>
            <td><strong>{{ $item->product_name }}</strong><br><small>{{ $item->product_sku ?: match($item->item_type){'service'=>'Serviço','freight'=>'Frete','expense'=>'Despesa',default=>'Produto'} }}</small>@if($item->notes)<br><small>{{ $item->notes }}</small>@endif</td>
            <td>R$ {{ number_format((float)$item->unit_price,2,',','.') }}</td>
            <td>{{ number_format((float)$item->quantity,3,',','.') }}</td>
            <td>R$ {{ number_format((float)$item->discount,2,',','.') }}</td>
            <td><strong>R$ {{ number_format((float)$item->line_total,2,',','.') }}</strong></td>
          </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td><strong>TOTAL</strong></td><td></td><td>{{ number_format((float)$sale->items->sum('quantity'),3,',','.') }}</td><td>R$ {{ number_format((float)$sale->discount_total,2,',','.') }}</td><td><strong>R$ {{ number_format((float)$sale->total,2,',','.') }}</strong></td></tr></tfoot>
      </table>
    </div>
    <label class="field sale-general-notes"><span>Observações gerais</span><textarea readonly rows="3">{{ $sale->notes }}</textarea></label>
  </section>

  @if($sale->payments->isNotEmpty())
  <section class="sale-finance-section">
    <div class="sale-section-title"><div><h2>Financeiro</h2><p>{{ $sale->payments->count() }} parcela(s)</p></div></div>
    <div class="sale-finance-table-wrap">
      <table class="sale-finance-table">
        <thead><tr><th>Parcela</th><th>Valor</th><th>Data de pagamento</th><th>Forma de pagamento</th><th>Situação</th></tr></thead>
        <tbody>
        @foreach($sale->payments as $payment)
          <tr><td>{{ $payment->installment }}/{{ $sale->payments->count() }}</td><td><strong>R$ {{ number_format((float)$payment->amount,2,',','.') }}</strong></td><td>{{ $payment->due_date?->format(\App\Models\AppSetting::dateFormat()) ?? '—' }}</td><td>{{ $paymentLabels[$payment->payment_method] ?? 'Não informado' }}</td><td>{{ $payment->status==='paid'?'Recebido':($payment->status==='cancelled'?'Cancelado':'Pendente') }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td><strong>TOTAL</strong></td><td><strong>R$ {{ number_format((float)$sale->payments->sum('amount'),2,',','.') }}</strong></td><td colspan="3"></td></tr></tfoot>
      </table>
    </div>
  </section>
  @endif
  @if(isset($saleReturns) && $saleReturns->isNotEmpty())
  <section class="sale-finance-section"><div class="sale-section-title"><div><h2>Devoluções</h2></div><a href="{{ route('sales.returns.index',['search'=>$sale->id]) }}">Ver todas</a></div><div class="sale-finance-table-wrap"><table class="sale-finance-table"><thead><tr><th>Código</th><th>Data</th><th>Itens</th><th>Valor</th><th>Situação</th></tr></thead><tbody>@foreach($saleReturns as $return)<tr><td><a href="{{ route('sales.returns.show',$return) }}">#{{ str_pad((string)$return->id,5,'0',STR_PAD_LEFT) }}</a></td><td>{{ $return->return_date->format(\App\Models\AppSetting::dateFormat()) }}</td><td>{{ $return->items_count }}</td><td>R$ {{ number_format((float)$return->total,2,',','.') }}</td><td>{{ $return->status==='completed'?'Concluída':'Cancelada' }}</td></tr>@endforeach</tbody></table></div></section>
  @endif
</div>

@if($sale->status==='completed')
<dialog class="erp-dialog small-dialog" id="sale-cancel">
  <form action="{{ route('sales.cancel',$sale) }}" method="post">@csrf
    <div class="dialog-header">
      <div><h2>Cancelar {{ $sale->operation_type==='quote'?'orçamento':'venda' }} #{{ $sale->id }}?</h2></div>
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

@if($sale->operation_type==='quote' && $sale->status==='completed')
<dialog class="erp-dialog small-dialog" id="sale-quote-convert">
  <form action="{{ route('sales.quote.convert',$sale) }}" method="post">
    @csrf
    <div class="dialog-header">
      <div><h2>Converter orçamento #{{ $sale->id }} em venda?</h2></div>
      <button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button>
    </div>
    <div class="dialog-body">
      <p>O orçamento será mantido no histórico. A nova venda usará os mesmos preços e itens, com verificação do estoque atual e lançamentos comerciais normais.</p>
      @if($sale->quote_expires_at && $sale->quote_expires_at->lt(today()))
      <p class="text-danger">O orçamento está vencido. Não é possível convertê-lo sem uma nova proposta.</p>
      @endif
    </div>
    <div class="dialog-footer">
      <button type="button" data-dialog-close class="btn btn-secondary">Voltar</button>
      <button class="btn btn-success" @disabled($sale->quote_expires_at && $sale->quote_expires_at->lt(today()))>Confirmar conversão</button>
    </div>
  </form>
</dialog>
@endif
@endsection
