@extends('layouts.app')
@section('title','Fiscal')
@section('titleMeta',$tabMeta['description'])
@section('actions')
  @if(auth()->user()->canAccess('settings'))
    <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>$configuration['settings_tab']]) }}">
      @include('partials.icon',['name'=>'settings','size'=>16]) Configurar {{ $tabMeta['label'] }}
    </a>
  @endif
@endsection

@section('content')
@include('fiscal._nav')

@php
  $total=array_sum($statusCounts);
  $prepared=(int)($statusCounts['prepared'] ?? 0)+(int)($statusCounts['pending'] ?? 0)+(int)($statusCounts['processing'] ?? 0);
  $authorized=(int)($statusCounts['authorized'] ?? 0);
  $problems=(int)($statusCounts['error'] ?? 0)+(int)($statusCounts['rejected'] ?? 0)+(int)($statusCounts['failed'] ?? 0);
  $environmentLabel=($configuration['environment'] ?? 'homologation')==='production' ? 'Produção' : 'Homologação';
@endphp

<div class="fiscal-summary-grid">
  <div class="fiscal-summary-card">
    <span>Documentos</span>
    <strong>{{ $total }}</strong>
    <small>{{ $tabMeta['label'] }} registrados no Nextor</small>
  </div>
  <div class="fiscal-summary-card">
    <span>Em processamento</span>
    <strong>{{ $prepared }}</strong>
    <small>Preparados, pendentes ou processando</small>
  </div>
  <div class="fiscal-summary-card">
    <span>Autorizados</span>
    <strong>{{ $authorized }}</strong>
    <small>Documentos com autorização registrada</small>
  </div>
  <div class="fiscal-summary-card {{ $problems>0?'has-alert':'' }}">
    <span>Com problema</span>
    <strong>{{ $problems }}</strong>
    <small>Erros, rejeições ou falhas</small>
  </div>
</div>

<section class="fiscal-config-strip">
  <div>
    <span>Fiscal geral</span>
    <strong class="status {{ $fiscalEnabled?'status-ok':'status-muted' }}">{{ $fiscalEnabled?'Habilitado':'Desabilitado' }}</strong>
  </div>
  <div>
    <span>{{ $tabMeta['label'] }}</span>
    <strong class="status {{ $configuration['enabled']?'status-ok':'status-muted' }}">{{ $configuration['enabled']?'Habilitada':'Desabilitada' }}</strong>
  </div>
  <div><span>Ambiente</span><strong>{{ $environmentLabel }}</strong></div>
  <div><span>Série</span><strong>{{ $configuration['series'] ?? '—' }}</strong></div>
  <div><span>Próximo número</span><strong>{{ $configuration['next_number'] ?? '—' }}</strong></div>
  <div><span>Transmissão</span><strong class="status status-muted">Conector externo</strong></div>
</section>

<section class="cms-card fiscal-module-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      @if($tab==='nfce' && auth()->user()->canAccess('pdv'))
        <a class="grid-primary-action" href="{{ route('pdv.index') }}">@include('partials.icon',['name'=>'pdv','size'=>17]) <span>Abrir PDV</span></a>
      @elseif(in_array($tab,['nfe','nfse'],true) && auth()->user()->canAccess('sales'))
        <a class="grid-primary-action" href="{{ route('sales.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova venda</span></a>
      @endif

      <div class="grid-tool-group">
        <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">@include('partials.icon',['name'=>'print','size'=>18])</button>
        <button class="grid-tool" type="button" data-export-table="fiscal-{{ $tab }}.csv" data-tooltip="Exportar CSV">@include('partials.icon',['name'=>'download','size'=>18])</button>
        <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">@include('partials.icon',['name'=>'refresh','size'=>18])</button>
      </div>
    </div>

    <div class="grid-actions-right">
      <button class="grid-filter-button" type="button" data-filter-toggle="fiscal-filters" data-tooltip="Pesquisar / filtrar">
        @include('partials.icon',['name'=>'search','size'=>18])
      </button>
    </div>
  </div>

  <div class="grid-filter-panel" id="fiscal-filters" @if(!$term && !$status && !$environment) hidden @endif>
    <form method="get" action="{{ route('fiscal.index') }}" class="toolbar-filters" data-live-search data-live-target="fiscal-live-results">
      <input type="hidden" name="tab" value="{{ $tab }}">
      <div class="table-search-group">
        <input type="search" autocomplete="off" name="search" value="{{ $term }}" placeholder="Número, venda, cliente, chave ou protocolo..." aria-label="Buscar documentos fiscais">
        <button type="submit" class="table-search-submit" data-tooltip="Pesquisar">@include('partials.icon',['name'=>'search','size'=>17])</button>
      </div>
      <select name="status" class="input-filter">
        <option value="">Todos os status</option>
        @foreach(['prepared'=>'Preparado','pending'=>'Pendente','processing'=>'Processando','authorized'=>'Autorizado','rejected'=>'Rejeitado','error'=>'Erro','failed'=>'Falha','cancelled'=>'Cancelado'] as $key=>$label)
          <option value="{{ $key }}" @selected($status===$key)>{{ $label }}</option>
        @endforeach
      </select>
      <select name="environment" class="input-filter">
        <option value="">Todos os ambientes</option>
        <option value="homologation" @selected($environment==='homologation')>Homologação</option>
        <option value="production" @selected($environment==='production')>Produção</option>
      </select>
      <button class="btn btn-secondary">Filtrar</button>
      @if($term || $status || $environment)<a class="btn btn-light" href="{{ route('fiscal.index',['tab'=>$tab]) }}">Limpar</a>@endif
    </form>
  </div>

  <div id="fiscal-live-results" data-live-search-results>
    <div class="table-scroll">
      <table class="cms-table fiscal-table">
        <thead>
          <tr>
            <th>Documento</th>
            <th>Preparado em</th>
            <th>Origem</th>
            <th>Cliente</th>
            <th>Valor</th>
            <th>Ambiente</th>
            <th>Status</th>
            <th>Chave / protocolo</th>
            <th class="action-cell"></th>
          </tr>
        </thead>
        <tbody>
        @forelse($documents as $document)
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
          <tr>
            <td>
              <a class="table-link" href="{{ route('fiscal.show',$document) }}">
                {{ $document->series!==null ? $document->series.'/' : '' }}{{ $document->document_number ?? '—' }}
              </a>
              <small class="table-subtitle">{{ $tabMeta['label'] }} #{{ $document->id }}</small>
            </td>
            <td class="nowrap">{{ ($document->prepared_at ?? $document->created_at)?->format($dateFormat.' H:i') }}</td>
            <td>
              @if($document->sale && auth()->user()->canAccess('sales'))
                <a class="table-link" href="{{ route('sales.show',$document->sale) }}">Venda #{{ $document->sale_id }}</a>
              @elseif($document->sale_id)
                Venda #{{ $document->sale_id }}
              @else
                —
              @endif
            </td>
            <td>{{ $document->sale?->customer?->name ?? 'Consumidor não identificado' }}</td>
            <td class="price-strong nowrap">{{ $document->sale ? 'R$ '.number_format((float)$document->sale->total,2,',','.') : '—' }}</td>
            <td>{{ $document->environment==='production'?'Produção':'Homologação' }}</td>
            <td><span class="status {{ $statusInfo[1] }}">{{ $statusInfo[0] }}</span></td>
            <td>
              @if($document->access_key)
                <span class="fiscal-key">{{ $document->access_key }}</span>
              @elseif($document->protocol)
                <span class="fiscal-key">{{ $document->protocol }}</span>
              @else
                <span class="table-subtitle">Aguardando processamento</span>
              @endif
            </td>
            <td class="action-cell"><a class="btn-icon" href="{{ route('fiscal.show',$document) }}" data-tooltip="Abrir documento">@include('partials.icon',['name'=>'chevron','size'=>16])</a></td>
          </tr>
        @empty
          <tr>
            <td colspan="9" class="empty-cell">
              Nenhum documento {{ $tabMeta['label'] }} encontrado.
              @if($tab==='cte') A estrutura do módulo já está pronta para receber CT-e pelo conector fiscal. @endif
            </td>
          </tr>
        @endforelse
        </tbody>
      </table>
    </div>
    @include('partials.table-footer',['paginator'=>$documents])
  </div>
</section>

<div class="fiscal-integration-note">
  @include('partials.icon',['name'=>'shield','size'=>18])
  <span>O módulo organiza documentos, status e origem da operação. Transmissão, consulta e eventos serão executados pelo conector fiscal externo; o motor fiscal próprio arquivado não é utilizado.</span>
</div>
@endsection
