@extends('layouts.app')
@section('title','Conferência de compra')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/fiscal-purchases.css') }}?v={{ filemtime(public_path('css/fiscal-purchases.css')) }}">
@endpush
@section('content')
@include('fiscal._nav')
<section class="cms-card sales-module-card fiscal-purchases-page">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a href="{{ route('fiscal.purchases.index') }}" class="grid-tool grid-tool-wide">
        @include('partials.icon',['name'=>'arrow-left','size'=>17]) <span>Voltar às compras</span>
      </a>
      @if($invoice->xml_path)
      <a class="grid-primary-action" href="{{ route('fiscal.purchases.xml',$invoice) }}">
        @include('partials.icon',['name'=>'download','size'=>17]) <span>Baixar XML</span>
      </a>
      @endif
    </div>
    <div class="grid-actions-right">
      <span class="status {{ $invoice->xml_path?'status-ok':'status-muted' }}">{{ $invoice->xml_path?'XML recebido':'Aguardando XML' }}</span>
    </div>
  </div>
  <div class="fiscal-purchases-detail">
    <h2>NF-e de entrada</h2>
    <div class="fiscal-purchases-info">
      <div><span>Fornecedor</span><strong>{{ $invoice->supplier_name ?: 'Não informado' }}</strong></div>
      <div><span>CNPJ</span><strong>{{ $invoice->supplier_cnpj ?: '—' }}</strong></div>
      <div><span>Emissão</span><strong>{{ $invoice->issued_at?->format('d/m/Y H:i') ?? '—' }}</strong></div>
      <div><span>Valor total</span><strong>{{ $invoice->total!==null?'R$ '.number_format((float)$invoice->total,2,',','.'):'—' }}</strong></div>
      <div class="wide"><span>Chave de acesso</span><strong class="fiscal-purchases-key">{{ $invoice->access_key }}</strong></div>
    </div>
    @if(!$invoice->xml_path)
      <p class="fiscal-purchases-alert">A SEFAZ disponibilizou o resumo. O XML completo pode depender da manifestação do destinatário. Não registre entrada no estoque com base apenas neste resumo.</p>
    @else
      <h3>Produtos da NF-e ({{ $invoice->item_count }})</h3>
      <div class="table-scroll">
        <table class="cms-table">
          <thead><tr><th>Item</th><th>Descrição</th><th>Código</th><th>NCM / CFOP</th><th class="align-right">Quantidade</th><th class="align-right">Unitário</th><th class="align-right">Total</th></tr></thead>
          <tbody>
          @forelse($invoice->items ?? [] as $item)
          <tr>
            <td>{{ $item['number'] ?? '—' }}</td>
            <td><strong>{{ $item['description'] ?? '—' }}</strong></td>
            <td>{{ $item['code'] ?? '—' }}</td>
            <td>{{ ($item['ncm'] ?? '—').' / '.($item['cfop'] ?? '—') }}</td>
            <td class="align-right">{{ $item['quantity'] ?? '—' }} {{ $item['unit'] ?? '' }}</td>
            <td class="align-right">{{ $item['unit_price'] ?? '—' }}</td>
            <td class="align-right">{{ $item['total'] ?? '—' }}</td>
          </tr>
          @empty
          <tr><td colspan="7" class="empty-cell">O XML não contém produtos para exibir.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      <p class="fiscal-purchases-help">Dados importados para conferência. O estoque e as contas a pagar permanecem inalterados.</p>
    @endif
  </div>
</section>
@endsection
