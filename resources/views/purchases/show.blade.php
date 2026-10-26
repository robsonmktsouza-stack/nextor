@extends('layouts.app')
@section('title','Compra #'.str_pad((string)$purchase->id,5,'0',STR_PAD_LEFT))
@section('content')
<section class="cms-card sales-module-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a class="grid-tool grid-tool-wide" href="{{ route('purchases.index') }}">@include('partials.icon',['name'=>'arrow-left','size'=>17]) Voltar às compras</a>
      @if($purchase->status==='draft')
      <a class="grid-primary-action" href="{{ route('purchases.edit',$purchase) }}">@include('partials.icon',['name'=>'edit','size'=>17]) Editar</a>
      <button class="grid-tool grid-tool-wide" type="button" data-dialog-open="purchase-receive">@include('partials.icon',['name'=>'check','size'=>17]) Confirmar recebimento</button>
      @endif
      @if($purchase->status!=='cancelled')<button class="grid-tool grid-tool-wide" type="button" data-dialog-open="purchase-cancel">@include('partials.icon',['name'=>'x','size'=>16]) Cancelar compra</button>@endif
      <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir" aria-label="Imprimir">@include('partials.icon',['name'=>'print','size'=>17])</button>
    </div>
    <div class="grid-actions-right">
      <span class="status {{ $purchase->status==='received'?'status-ok':($purchase->status==='draft'?'status-blue':'status-muted') }}">{{ match($purchase->status){'received'=>'Recebida','cancelled'=>'Cancelada',default=>'Rascunho'} }}</span>
    </div>
  </div>
  <div class="purchase-detail-wrap">
    <h2>Dados da compra</h2>
    <div class="purchase-detail-grid">
      <div><span>Número</span><strong>#{{ str_pad((string)$purchase->id,5,'0',STR_PAD_LEFT) }}</strong></div>
      <div><span>Fornecedor</span><strong>{{ $purchase->supplier?->name }}</strong></div>
      <div><span>Data</span><strong>{{ $purchase->operation_date?->format('d/m/Y') }}</strong></div>
      <div><span>Documento</span><strong>{{ $purchase->document_number ?: '—' }}</strong></div>
      <div><span>Palavra-chave</span><strong>{{ $purchase->keyword ?: '—' }}</strong></div>
      <div><span>Recebimento</span><strong>{{ $purchase->received_at?->format('d/m/Y H:i') ?? 'Não recebido' }}</strong></div>
      <div><span>Vencimento</span><strong>{{ $purchase->due_date?->format('d/m/Y') ?: '—' }}</strong></div>
      <div><span>NF-e vinculada</span><strong>
        @if($purchase->invoice)<a href="{{ route('fiscal.purchases.show',$purchase->invoice) }}">Ver NF-e recebida</a>@else — @endif
      </strong></div>
      <div><span>Financeiro</span><strong>
        @if($purchase->payable)<a href="{{ route('finance.entries.show',$purchase->payable) }}">Conta a pagar #{{ $purchase->payable->id }}</a>
        @elseif($purchase->generate_payable)A gerar no recebimento
        @else Sem lançamento @endif
      </strong></div>
    </div>
    <div class="purchase-section-heading"><h2>Produtos ({{ $purchase->items->count() }})</h2></div>
    <div class="purchase-items purchase-readonly-items">
      @foreach($purchase->items as $item)
      <div class="purchase-item-line">
        <div class="purchase-item-primary purchase-readonly-primary">
          <div class="purchase-readonly-data purchase-product-field">
            <span>Produto</span>
            <strong>{{ $item->source_description ?: $item->product_name }}</strong>
            @if($item->source_code)<small>Código NF-e: {{ $item->source_code }}</small>@endif
            @if(!$item->product_id)<small class="purchase-unlinked">Pendente de vínculo com o estoque</small>@endif
            <small>{{ $item->product_id ? $item->product_sku.' · '.$item->unit : ($item->source_unit ?: 'UN') }}</small>
          </div>
          <div class="purchase-readonly-data">
            <span>Quantidade</span>
            <strong>{{ number_format((float)$item->quantity,3,',','.') }}</strong>
          </div>
          <div class="purchase-readonly-data">
            <span>Custo unitário</span>
            <strong>R$ {{ number_format((float)$item->unit_cost,2,',','.') }}</strong>
          </div>
          <div class="purchase-item-amount">
            <span>Total do item</span>
            <strong>R$ {{ number_format((float)$item->line_total,2,',','.') }}</strong>
          </div>
        </div>
        <div class="purchase-item-charges purchase-readonly-charges">
          <div class="purchase-readonly-data"><span>Desconto</span><strong>R$ {{ number_format((float)$item->discount,2,',','.') }}</strong></div>
          <div class="purchase-readonly-data"><span>Outros custos</span><strong>R$ {{ number_format((float)$item->other_costs,2,',','.') }}</strong></div>
          <div class="purchase-readonly-data"><span>Custos externos</span><strong>R$ {{ number_format((float)$item->external_costs,2,',','.') }}</strong></div>
          <div class="purchase-readonly-data"><span>IPI</span><strong>R$ {{ number_format((float)$item->ipi,2,',','.') }}</strong></div>
          <div class="purchase-readonly-data"><span>ICMS-ST</span><strong>R$ {{ number_format((float)$item->icms_st,2,',','.') }}</strong></div>
        </div>
      </div>
      @endforeach
    </div>
    <div class="purchase-detail-totals">
      <span>Itens: R$ {{ number_format((float)$purchase->subtotal,2,',','.') }}</span>
      <span>Frete: R$ {{ number_format((float)$purchase->freight,2,',','.') }}</span>
      <span>Outras despesas: R$ {{ number_format((float)$purchase->other_expenses,2,',','.') }}</span>
      <span>Desconto: R$ {{ number_format((float)$purchase->discount,2,',','.') }}</span>
      <strong>Total R$ {{ number_format((float)$purchase->total,2,',','.') }}</strong>
    </div>
    @if($purchase->notes)<div class="purchase-notes"><h3>Observações</h3><p>{{ $purchase->notes }}</p></div>@endif
  </div>
</section>
@if($purchase->status==='draft')
<dialog class="erp-dialog small-dialog" id="purchase-receive">
  <form method="post" action="{{ route('purchases.receive',$purchase) }}">@csrf
    <div class="dialog-header"><h2>Confirmar recebimento</h2><button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">×</button></div>
    <div class="dialog-body"><p>Confirma a entrada dos produtos no estoque? {{ $purchase->generate_payable?'Será gerada uma conta a pagar no Financeiro.':'Nenhuma conta a pagar será gerada.' }}</p></div>
    <div class="dialog-footer"><button type="button" class="btn btn-secondary" data-dialog-close>Voltar</button><button type="submit" class="btn btn-success">Confirmar recebimento</button></div>
  </form>
</dialog>
@endif
@if($purchase->status!=='cancelled')
<dialog class="erp-dialog small-dialog" id="purchase-cancel">
  <form method="post" action="{{ route('purchases.cancel',$purchase) }}">@csrf
    <div class="dialog-header"><h2>Cancelar compra</h2><button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">×</button></div>
    <div class="dialog-body"><p>O cancelamento de uma compra recebida estorna o estoque e cancela o título a pagar, desde que não existam pagamentos. Não é possível estornar estoque já consumido quando o saldo negativo está bloqueado.</p></div>
    <div class="dialog-footer"><button type="button" class="btn btn-secondary" data-dialog-close>Voltar</button><button type="submit" class="btn btn-danger-outline">Cancelar compra</button></div>
  </form>
</dialog>
@endif
@push('styles')<link rel="stylesheet" href="{{ asset('css/purchases.css') }}?v={{ filemtime(public_path('css/purchases.css')) }}">@endpush
@endsection
