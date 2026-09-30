@extends('layouts.app')
@section('title','Venda #'.str_pad($sale->id,5,'0',STR_PAD_LEFT))
@section('description','Detalhamento da operação e produtos movimentados.')
@section('actions')<a class="btn btn-secondary" href="{{ route('sales.index') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
@if($sale->status==='completed')<button class="btn btn-danger-outline" data-dialog-open="sale-cancel" type="button">@include('partials.icon',['name'=>'x','size'=>16]) Cancelar venda</button>@endif
@endsection
@section('content')
<div class="detail-grid"><section class="cms-card"><div class="card-header"><div><h2>Itens da venda</h2><p>{{ $sale->items->count() }} produto(s) nesta operação</p></div><span class="status {{ $sale->status==='completed'?'status-ok':'status-muted' }}">{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</span></div>
<div class="table-scroll"><table class="cms-table"><thead><tr><th>SKU</th><th>Produto</th><th>Quantidade</th><th>Preço unit.</th><th>Subtotal</th></tr></thead><tbody>
@foreach($sale->items as $item)<tr><td><span class="code-tag">{{ $item->product_sku }}</span></td><td><strong class="table-title">{{ $item->product_name }}</strong></td><td>{{ number_format((float)$item->quantity,3,',','.') }}</td><td>R$ {{ number_format((float)$item->unit_price,2,',','.') }}</td><td class="price-strong">R$ {{ number_format((float)$item->line_total,2,',','.') }}</td></tr>@endforeach
</tbody></table></div><div class="detail-total">Total da venda <strong>R$ {{ number_format((float)$sale->total,2,',','.') }}</strong></div></section>
<aside class="cms-card"><div class="card-header"><div><h2>Dados da operação</h2><p>Informações de registro</p></div></div>
<div class="detail-meta"><div><span>Cliente</span><strong>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</strong></div><div><span>Data de conclusão</span><strong>{{ $sale->completed_at?->format('d/m/Y H:i') }}</strong></div><div><span>Responsável</span><strong>{{ $sale->user?->name ?? '—' }}</strong></div><div><span>Status</span><strong>{{ $sale->status==='completed'?'Concluída':'Cancelada' }}</strong></div>@if($sale->cancelled_at)<div><span>Data do cancelamento</span><strong>{{ $sale->cancelled_at->format('d/m/Y H:i') }}</strong></div>@endif
@if($sale->notes)<div><span>Observações</span><strong>{{ $sale->notes }}</strong></div>@endif</div></aside></div>
@if($sale->status==='completed')<dialog class="erp-dialog small-dialog" id="sale-cancel"><form action="{{ route('sales.cancel',$sale) }}" method="post">@csrf
<div class="dialog-header"><div><h2>Cancelar venda #{{ $sale->id }}?</h2><p>Confirmação de estorno</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div><div class="dialog-body"><p>Esta operação cancelará a venda e devolverá os produtos ao estoque. A movimentação de estorno ficará registrada no histórico.</p></div><div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Manter venda</button><button class="btn btn-danger">Confirmar cancelamento</button></div></form></dialog>@endif
@endsection
