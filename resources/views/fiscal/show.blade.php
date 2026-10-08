@extends('layouts.app')
@section('title',$tabMeta['label'].' '.($document->series!==null ? $document->series.'/' : '').($document->document_number ?? '#'.$document->id))
@section('titleMeta','Documento fiscal')
@section('actions')
  <a class="btn btn-secondary" href="{{ route('fiscal.index',['tab'=>$tab]) }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
  @if(auth()->user()->canAccess('settings'))
    <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>$configuration['settings_tab']]) }}">@include('partials.icon',['name'=>'settings','size'=>16]) Configurar</a>
  @endif
@endsection

@section('content')
@include('fiscal._nav')

@php
  $statusInfo=match($document->status){
    'authorized'=>['Autorizado','status-ok'],
    'processing'=>['Processando','status-blue'],
    'pending'=>['Pendente','status-blue'],
    'rejected'=>['Rejeitado','status-danger'],
    'error'=>['Erro','status-danger'],
    'failed'=>['Falha','status-danger'],
    'cancelled'=>['Cancelado','status-muted'],
    default=>['Preparado','status-blue'],
  };
@endphp

<div class="fiscal-detail-status">
  <div><span>Status</span><strong class="status {{ $statusInfo[1] }}">{{ $statusInfo[0] }}</strong></div>
  <div><span>Ambiente</span><strong>{{ $document->environment==='production'?'Produção':'Homologação' }}</strong></div>
  <div><span>Emissão</span><strong>{{ $document->emission_mode==='offline'?'Contingência':'Normal' }}</strong></div>
  <div><span>Preparado em</span><strong>{{ ($document->prepared_at ?? $document->created_at)?->format($dateFormat.' H:i') }}</strong></div>
</div>

@if($tab==='nfce' && $document->status==='prepared')
  @if(count($nfcePreflightErrors))
    <section class="fiscal-error-card">
      @include('partials.icon',['name'=>'alert','size'=>20])
      <div>
        <strong>Dados pendentes antes da transmissão</strong>
        <ul>
          @foreach($nfcePreflightErrors as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    </section>
  @else
    <section class="fiscal-info-card">
      <strong>Pré-validação local aprovada</strong>
      <p>Dados básicos conferidos. A emissão ACBr e a autorização SEFAZ ainda não foram executadas.</p>
    </section>
  @endif
@endif

<div class="fiscal-detail-grid">
  <section class="cms-card fiscal-detail-card">
    <div class="card-header"><div><h2>Documento</h2><p>Identificação e retorno fiscal registrado.</p></div></div>
    <dl class="fiscal-data-list">
      <div><dt>Modelo</dt><dd>{{ $tabMeta['label'] }}</dd></div>
      <div><dt>Série / número</dt><dd>{{ $document->series ?? '—' }} / {{ $document->document_number ?? '—' }}</dd></div>
      <div><dt>Chave de acesso</dt><dd class="fiscal-key">{{ $document->access_key ?: '—' }}</dd></div>
      <div><dt>Protocolo</dt><dd>{{ $document->protocol ?: '—' }}</dd></div>
      <div><dt>Autorizado em</dt><dd>{{ $document->authorized_at?->format($dateFormat.' H:i:s') ?? '—' }}</dd></div>
      <div><dt>Processado em</dt><dd>{{ $document->processed_at?->format($dateFormat.' H:i:s') ?? '—' }}</dd></div>
    </dl>
  </section>

  <section class="cms-card fiscal-detail-card">
    <div class="card-header"><div><h2>Origem</h2><p>Operação comercial relacionada ao documento.</p></div></div>
    <dl class="fiscal-data-list">
      <div><dt>Venda</dt><dd>
        @if($document->sale)
          @if(auth()->user()->canAccess('sales'))<a class="table-link" href="{{ route('sales.show',$document->sale) }}">#{{ $document->sale_id }}</a>@else #{{ $document->sale_id }} @endif
        @else — @endif
      </dd></div>
      <div><dt>Cliente</dt><dd>{{ $document->sale?->customer?->name ?? 'Consumidor não identificado' }}</dd></div>
      <div><dt>Responsável</dt><dd>{{ $document->sale?->user?->name ?? '—' }}</dd></div>
      <div><dt>Valor da operação</dt><dd>{{ $document->sale ? 'R$ '.number_format((float)$document->sale->total,2,',','.') : '—' }}</dd></div>
      <div><dt>Forma(s) de pagamento</dt><dd>{{ $document->sale?->payments?->pluck('payment_method')->filter()->unique()->implode(', ') ?: '—' }}</dd></div>
    </dl>
  </section>
</div>

@if($document->error_message)
  <section class="fiscal-error-card">
    @include('partials.icon',['name'=>'alert','size'=>20])
    <div><strong>Retorno com problema</strong><p>{{ $document->error_message }}</p></div>
  </section>
@endif

@if($document->contingency_reason)
  <section class="fiscal-info-card">
    <strong>Contingência</strong>
    <p>{{ $document->contingency_reason }}</p>
  </section>
@endif

@if($document->cancellation_status || $document->cancelled_at)
  <section class="cms-card fiscal-detail-card">
    <div class="card-header"><div><h2>Cancelamento</h2><p>Estado do evento de cancelamento.</p></div></div>
    <dl class="fiscal-data-list">
      <div><dt>Status</dt><dd>{{ $document->cancellation_status ?: ($document->cancelled_at?'Concluído':'—') }}</dd></div>
      <div><dt>Solicitado em</dt><dd>{{ $document->cancellation_requested_at?->format($dateFormat.' H:i:s') ?? '—' }}</dd></div>
      <div><dt>Cancelado em</dt><dd>{{ $document->cancelled_at?->format($dateFormat.' H:i:s') ?? '—' }}</dd></div>
      <div><dt>Motivo</dt><dd>{{ $document->cancellation_reason ?: '—' }}</dd></div>
    </dl>
  </section>
@endif
@endsection
