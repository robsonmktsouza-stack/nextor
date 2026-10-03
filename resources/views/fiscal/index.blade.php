@extends('layouts.app')
@section('title','Fiscal')
@section('actions')
  @if(auth()->user()->canAccess('settings'))
    <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>$configuration['settings_tab']]) }}">
      @include('partials.icon',['name'=>'settings','size'=>16]) Configurações
    </a>
  @endif
@endsection

@section('content')
@include('fiscal._nav')

@php
  $statusOptions=[
    'prepared'=>'Preparada',
    'pending'=>'Pendente',
    'processing'=>'Processando',
    'authorized'=>'Autorizada',
    'rejected'=>'Rejeitada',
    'error'=>'Erro',
    'failed'=>'Falha',
    'cancelled'=>'Cancelada',
  ];
@endphp

<section class="cms-card fiscal-module-card">
  <div class="fiscal-actionbar">
    <div class="fiscal-actions-left">
      @if($tab==='nfce' && auth()->user()->canAccess('pdv'))
        <a class="fiscal-new-action" href="{{ route('pdv.index') }}" data-tooltip="Abrir PDV para nova NFC-e">
          @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span>
        </a>
      @elseif(in_array($tab,['nfe','nfse'],true) && auth()->user()->canAccess('sales'))
        <a class="fiscal-new-action" href="{{ route('sales.create') }}" data-tooltip="Criar documento a partir de nova venda">
          @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span>
        </a>
      @endif

      <div class="fiscal-tool-group">
        <button class="fiscal-tool" type="button" data-refresh-page data-tooltip="Atualizar">
          @include('partials.icon',['name'=>'refresh','size'=>18])
        </button>
        <button class="fiscal-tool" type="button" data-print-page data-tooltip="Imprimir listagem">
          @include('partials.icon',['name'=>'print','size'=>18])
        </button>
        <button class="fiscal-tool" type="button" data-export-table="fiscal-{{ $tab }}-{{ $month }}.csv" data-tooltip="Exportar CSV">
          @include('partials.icon',['name'=>'download','size'=>18])
        </button>
      </div>

      <div class="bulk-actions">
        <select class="bulk-action-select" data-bulk-menu disabled aria-label="Ações em massa">
          <option value="">Ações em massa</option>
          <option value="local:export">Exportar selecionadas</option>
          <option value="local:print">Imprimir selecionadas</option>
        </select>
        <button class="bulk-apply" type="button" data-bulk-apply disabled>Aplicar</button>
      </div>
      <span class="selection-count" data-selection-count hidden></span>
    </div>

    <div class="fiscal-period-controls">
      <a class="fiscal-current-month" href="{{ route('fiscal.index',array_merge(request()->except('page','month'),['tab'=>$tab,'month'=>now()->format('Y-m')])) }}">Mês atual</a>
      <a class="fiscal-period-arrow" href="{{ route('fiscal.index',array_merge(request()->except('page','month'),['tab'=>$tab,'month'=>$prevMonth])) }}" aria-label="Mês anterior">
        @include('partials.icon',['name'=>'chevron-left','size'=>19])
      </a>
      <strong class="fiscal-period-label">{{ $monthLabel }}</strong>
      <a class="fiscal-period-arrow" href="{{ route('fiscal.index',array_merge(request()->except('page','month'),['tab'=>$tab,'month'=>$nextMonth])) }}" aria-label="Próximo mês">
        @include('partials.icon',['name'=>'chevron','size'=>19])
      </a>
      <button class="fiscal-search-toggle" type="button" data-filter-toggle="fiscal-filters" data-tooltip="Pesquisar / filtrar" aria-label="Pesquisar / filtrar">
        @include('partials.icon',['name'=>'search','size'=>18])
      </button>
    </div>
  </div>

  <div class="grid-filter-panel fiscal-filter-panel" id="fiscal-filters" @if(!$term && !$status && !$environment) hidden @endif>
    <form method="get" action="{{ route('fiscal.index') }}" class="toolbar-filters" data-live-search data-live-target="fiscal-live-results">
      <input type="hidden" name="tab" value="{{ $tab }}">
      <input type="hidden" name="month" value="{{ $month }}">
      <div class="table-search-group">
        <input type="search" autocomplete="off" name="search" value="{{ $term }}" placeholder="Número, venda, cliente, chave ou protocolo..." aria-label="Buscar documentos fiscais">
        <button type="submit" class="table-search-submit" data-tooltip="Pesquisar">@include('partials.icon',['name'=>'search','size'=>17])</button>
      </div>
      <select name="status" class="input-filter">
        <option value="">Todas as situações</option>
        @foreach($statusOptions as $key=>$label)
          <option value="{{ $key }}" @selected($status===$key)>{{ $label }}</option>
        @endforeach
      </select>
      <select name="environment" class="input-filter">
        <option value="">Todos os ambientes</option>
        <option value="homologation" @selected($environment==='homologation')>Homologação</option>
        <option value="production" @selected($environment==='production')>Produção</option>
      </select>
      <button class="btn btn-secondary">Filtrar</button>
      @if($term || $status || $environment)
        <a class="btn btn-light" href="{{ route('fiscal.index',['tab'=>$tab,'month'=>$month]) }}">Limpar</a>
      @endif
    </form>
  </div>

  <div id="fiscal-live-results" data-live-search-results>
    <div class="table-scroll">
      <table class="cms-table fiscal-table">
        <thead>
          <tr>
            <th class="select-cell"><input type="checkbox" data-check-all aria-label="Selecionar todos"></th>
            <th>{{ $tabMeta['label'] }}</th>
            <th>{{ $tabMeta['party'] }}</th>
            <th>Série</th>
            <th>{{ in_array($tab,['nfe','nfce'],true) ? 'CFOP' : ($tab==='nfse' ? 'Serviço' : 'Operação') }}</th>
            <th>Venda</th>
            <th>Emissão</th>
            <th>Total</th>
            <th>Situação</th>
            <th class="action-cell"></th>
          </tr>
        </thead>
        <tbody>
        @forelse($documents as $document)
          @php
            $statusInfo=match($document->status){
              'authorized'=>['Autorizada','status-ok'],
              'processing'=>['Processando','status-blue'],
              'pending'=>['Pendente','status-blue'],
              'rejected'=>['Rejeitada','status-danger'],
              'error'=>['Erro','status-danger'],
              'failed'=>['Falha','status-danger'],
              'cancelled'=>['Cancelada','status-muted'],
              default=>['Preparada','status-blue'],
            };
            $rowClass=match($document->status){
              'authorized'=>'fiscal-row-authorized',
              'rejected','error','failed'=>'fiscal-row-problem',
              'cancelled'=>'fiscal-row-muted',
              default=>'',
            };
            $firstItem=data_get($document->source_snapshot,'items.0',[]);
            $operationCode=match($tab){
              'nfe','nfce'=>data_get($firstItem,'tax_defaults.cfop')
                ?? data_get($firstItem,'tax_defaults.cfop_code')
                ?? '—',
              'nfse'=>data_get($firstItem,'service_list_item')
                ?? data_get($firstItem,'national_tax_code')
                ?? '—',
              default=>'—',
            };
            $party=$document->sale?->customer?->name
              ?? data_get($document->source_snapshot,'consumer_name')
              ?? 'Consumidor não identificado';
            $emissionDate=$document->sale?->operation_date
              ?? $document->prepared_at
              ?? $document->created_at;
          @endphp
          <tr class="{{ $rowClass }}">
            <td class="select-cell"><input type="checkbox" data-row-select value="{{ $document->id }}" aria-label="Selecionar {{ $tabMeta['label'] }} {{ $document->document_number ?? $document->id }}"></td>
            <td>
              <a class="table-link fiscal-document-number" href="{{ route('fiscal.show',$document) }}">
                {{ $document->document_number ?? '—' }}
              </a>
            </td>
            <td>{{ $party }}</td>
            <td class="nowrap">{{ $document->series ?? '—' }}</td>
            <td class="fiscal-operation-code">{{ $operationCode }}</td>
            <td>
              @if($document->sale && auth()->user()->canAccess('sales'))
                <a class="table-link" href="{{ route('sales.show',$document->sale) }}">#{{ $document->sale_id }}</a>
              @elseif($document->sale_id)
                #{{ $document->sale_id }}
              @else
                —
              @endif
            </td>
            <td class="nowrap">{{ $emissionDate?->format($dateFormat) ?? '—' }}</td>
            <td class="price-strong nowrap">{{ $document->sale ? 'R$ '.number_format((float)$document->sale->total,2,',','.') : '—' }}</td>
            <td><span class="status {{ $statusInfo[1] }}">{{ $statusInfo[0] }}</span></td>
            <td class="action-cell">
              <a class="btn-icon" href="{{ route('fiscal.show',$document) }}" data-tooltip="Abrir documento" aria-label="Abrir documento">
                @include('partials.icon',['name'=>'chevron','size'=>16])
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="10" class="empty-cell">Nenhuma {{ $tabMeta['label'] }} encontrada em {{ $monthLabel }}.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
          <tr>
            <td></td>
            <td colspan="6"><strong>TOTAL LISTADO: {{ $documents->total() }} {{ $documents->total()===1 ? 'nota' : 'notas' }}</strong></td>
            <td class="price-strong nowrap">R$ {{ number_format($listedAmount,2,',','.') }}</td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    @include('partials.table-footer',['paginator'=>$documents])
  </div>
</section>
@endsection
