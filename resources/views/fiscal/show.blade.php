@extends('layouts.app')
@section('title',$tabMeta['label'].' '.($document->series!==null ? $document->series.'/' : '').($document->document_number ?? '#'.$document->id))
@section('titleMeta','Documento fiscal')
@section('actions')
  <a class="btn btn-secondary" href="{{ route('fiscal.index',['tab'=>$tab]) }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
  @if($tab==='nfce' && $document->status==='prepared' && empty($nfcePreflightErrors) && auth()->user()->canAccess('fiscal'))
    <form method="post" action="{{ route('fiscal.nfce.emit',$document) }}" data-confirm-submit="Enviar esta NFC-e para a SEFAZ?">
      @csrf
      <button type="submit" class="btn btn-success">@include('partials.icon',['name'=>'check','size'=>16]) Emitir NFC-e</button>
    </form>
  @endif
  @if($tab==='nfce' && $document->status==='pending' && $document->access_key && auth()->user()->canAccess('fiscal'))
    <form method="post" action="{{ route('fiscal.nfce.consult',$document) }}">
      @csrf
      <button type="submit" class="btn btn-secondary">@include('partials.icon',['name'=>'refresh-cw','size'=>16]) Consultar SEFAZ</button>
    </form>
  @endif
  @if($tab==='nfce' && $document->xml_path && auth()->user()->canAccess('fiscal'))
    <a class="btn btn-secondary" href="{{ route('fiscal.nfce.xml',$document) }}">
      @include('partials.icon',['name'=>'download','size'=>16]) {{ $document->status==='authorized' && str_ends_with($document->xml_path,'authorized.xml') ? 'XML autorizado' : 'XML assinado' }}
    </a>
  @endif
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
  @if(!$document->access_key && !$document->protocol && !$document->xml_path && !$document->response_path && !$document->authorized_at && auth()->user()->canAccess('fiscal'))
    <section class="cms-card fiscal-detail-card">
      <div class="card-header">
        <div>
          <h2>Conferência dos produtos</h2>
          <p>Corrija os campos fiscais no cadastro do produto e atualize os dados desta NFC-e. Não é necessário fazer outra venda.</p>
        </div>
      </div>
      <div style="padding:16px;display:grid;gap:14px">
        @foreach(($document->source_snapshot['items'] ?? []) as $index => $fiscalItem)
          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
              <strong>{{ $index + 1 }}. {{ $fiscalItem['name'] ?? 'Produto' }}</strong>
              <div style="font-size:12px;color:var(--text-muted,#64748b);margin-top:4px">
                NCM: {{ $fiscalItem['ncm'] ?: 'Não informado' }}
                · PIS: {{ data_get($fiscalItem,'tax_defaults.pis_cst') ?: 'Não informado' }}
                · COFINS: {{ data_get($fiscalItem,'tax_defaults.cofins_cst') ?: 'Não informado' }}
              </div>
            </div>
            @if(!empty($fiscalItem['product_id']) && auth()->user()->canAccess('products'))
              <a class="btn btn-secondary" target="_blank" rel="noopener noreferrer" href="{{ route('products.edit',$fiscalItem['product_id']) }}">
                @include('partials.icon',['name'=>'edit','size'=>16]) Corrigir cadastro
              </a>
            @endif
          </div>
        @endforeach
        <form method="post" action="{{ route('fiscal.nfce.refresh-data',$document) }}">
          @csrf
          <button type="submit" class="btn btn-secondary">
            @include('partials.icon',['name'=>'refresh-cw','size'=>16]) Atualizar dados fiscais desta NFC-e
          </button>
        </form>
        <small style="color:var(--text-muted,#64748b)">Mantém os itens, valores, pagamentos, série e número da venda original. Só atualiza a classificação fiscal dos produtos antes de qualquer assinatura ou transmissão.</small>
      </div>
    </section>
  @endif
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
