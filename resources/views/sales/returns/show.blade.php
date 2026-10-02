@extends('layouts.app')
@section('title','Devolução #'.str_pad((string)$saleReturn->id,5,'0',STR_PAD_LEFT))
@section('actions')
<a class="btn btn-secondary" href="{{ route('sales.returns.index') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
@if($saleReturn->status==='completed')
<button class="btn btn-danger-outline" type="button" data-dialog-open="return-cancel">@include('partials.icon',['name'=>'x','size'=>16]) Cancelar devolução</button>
@endif
@endsection
@section('content')
@include('sales._nav')

<div class="detail-grid">
  <section class="cms-card">
    <div class="card-header">
      <div>
        <h2>Itens devolvidos</h2>
        <p>{{ $saleReturn->items->count() }} item(ns) vinculados à venda #{{ str_pad((string)$saleReturn->sale_id,5,'0',STR_PAD_LEFT) }}</p>
      </div>
      <span class="status {{ $saleReturn->status==='completed'?'status-ok':'status-muted' }}">{{ $saleReturn->status==='completed'?'Concluída':'Cancelada' }}</span>
    </div>

    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Tipo</th><th>Código</th><th>Item</th><th>Quantidade</th><th>Preço original</th><th>Valor devolvido</th><th>Motivo</th></tr></thead>
        <tbody>
        @foreach($saleReturn->items as $item)
          <tr>
            <td>{{ match($item->item_type){'service'=>'Serviço','freight'=>'Frete','expense'=>'Despesa',default=>'Produto'} }}</td>
            <td><span class="code-tag">{{ $item->item_code ?: '—' }}</span></td>
            <td><strong class="table-title">{{ $item->item_name }}</strong></td>
            <td>{{ number_format((float)$item->quantity,3,',','.') }}</td>
            <td>R$ {{ number_format((float)$item->unit_price,2,',','.') }}</td>
            <td class="price-strong">R$ {{ number_format((float)$item->line_total,2,',','.') }}</td>
            <td>{{ $item->reason ?: '—' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>

    <div class="detail-total">Valor devolvido <strong>R$ {{ number_format((float)$saleReturn->total,2,',','.') }}</strong></div>
  </section>

  <aside class="cms-card">
    <div class="card-header"><div><h2>Dados da devolução</h2><p>Vínculo com a operação original</p></div></div>
    <div class="detail-meta">
      <div><span>Venda original</span><strong><a class="table-link" href="{{ route('sales.show',$saleReturn->sale_id) }}">#{{ str_pad((string)$saleReturn->sale_id,5,'0',STR_PAD_LEFT) }}</a></strong></div>
      <div><span>Cliente</span><strong>{{ $saleReturn->sale?->customer?->name ?? 'Consumidor não identificado' }}</strong></div>
      <div><span>Data</span><strong>{{ $saleReturn->return_date->format(\App\Models\AppSetting::dateFormat()) }}</strong></div>
      <div><span>Responsável</span><strong>{{ $saleReturn->user?->name ?? '—' }}</strong></div>
      <div><span>Situação</span><strong>{{ $saleReturn->status==='completed'?'Concluída':'Cancelada' }}</strong></div>
      @if($saleReturn->notes)<div><span>Observações</span><strong>{{ $saleReturn->notes }}</strong></div>@endif
    </div>
  </aside>
</div>

@if($saleReturn->status==='completed')
<dialog class="erp-dialog small-dialog" id="return-cancel">
  <form method="post" action="{{ route('sales.returns.cancel',$saleReturn) }}">
    @csrf
    <div class="dialog-header">
      <div><h2>Cancelar devolução?</h2><p>O estoque devolvido será retirado novamente.</p></div>
      <button type="button" class="close-dialog" data-dialog-close>@include('partials.icon',['name'=>'x'])</button>
    </div>
    <div class="dialog-body"><p>O cancelamento só será concluído se houver estoque suficiente para reverter os produtos retornados.</p></div>
    <div class="dialog-footer">
      <button type="button" class="btn btn-secondary" data-dialog-close>Voltar</button>
      <button class="btn btn-danger">Confirmar cancelamento</button>
    </div>
  </form>
</dialog>
@endif
@endsection
