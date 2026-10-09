@extends('layouts.app')
@section('title','Fiscal')
@section('description','Emissão e acompanhamento dos documentos fiscais do NEXTOR.')
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
  $fiscalUtilities=app(\App\Services\Fiscal\FiscalDocumentUtilityService::class);
  $auxiliaryLabel=match($tab){
    'nfse'=>'DANFSe', 'cte'=>'DACTE', 'mdfe'=>'DAMDFE',
    default=>'DANFE',
  };
@endphp

<section class="cms-card sales-module-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      @if($tab==='nfe')
        <a class="grid-primary-action" href="{{ route('fiscal.nfe.create') }}" data-tooltip="Criar nova NF-e">
          @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span>
        </a>
      @elseif($tab==='nfce')
        <a class="grid-primary-action" href="{{ route('fiscal.nfce.create') }}" data-tooltip="Nova NFC-e">
          @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span>
        </a>
      @elseif($tab==='nfse' && auth()->user()->canAccess('sales'))
        <a class="grid-primary-action" href="{{ route('sales.create') }}" data-tooltip="Criar a partir de uma venda">
          @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span>
        </a>
      @endif

      <div class="grid-tool-group">
        <button class="grid-tool" type="button" data-print-page data-tooltip="Imprimir">
          @include('partials.icon',['name'=>'print','size'=>18])
        </button>
        <button class="grid-tool" type="button" data-export-table="fiscal-{{ $tab }}-{{ $month }}.csv" data-tooltip="Exportar CSV">
          @include('partials.icon',['name'=>'download','size'=>18])
        </button>
        <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar">
          @include('partials.icon',['name'=>'refresh','size'=>18])
        </button>
        @if($tab==='nfe')
          <a class="grid-tool grid-tool-wide" href="{{ route('fiscal.nfe.drafts.index') }}" data-tooltip="Rascunhos de NF-e">
            @include('partials.icon',['name'=>'edit','size'=>17]) <span>Rascunhos</span>
          </a>
          <a class="grid-tool" href="{{ route('fiscal.nfe.natures.index') }}" data-tooltip="Naturezas de Operação">
            @include('partials.icon',['name'=>'tag','size'=>18])
          </a>
        @endif
        @if($tab==='nfce' && auth()->user()->canAccess('pdv'))
          <a class="grid-tool" href="{{ route('pdv.index') }}" data-tooltip="Abrir PDV">
            @include('partials.icon',['name'=>'sales','size'=>17])
          </a>
        @endif
        @if(auth()->user()->canAccess('settings') && $tab==='nfce')
          <a class="grid-tool grid-tool-wide" href="{{ route('fiscal.tax-groups.index') }}" data-tooltip="Grupos tributários de produtos e serviços">
            @include('partials.icon',['name'=>'layers','size'=>17]) <span>Grupos tributários</span>
          </a>
          <a class="grid-tool" href="{{ route('fiscal.tax-rules.index') }}" data-tooltip="Regras específicas por NCM / produto">
            @include('partials.icon',['name'=>'tag','size'=>17])
          </a>
        @endif
        @if(auth()->user()->canAccess('settings'))
          <a class="grid-tool" href="{{ route('settings.index',['tab'=>$configuration['settings_tab']]) }}" data-tooltip="Configurações">
            @include('partials.icon',['name'=>'settings','size'=>18])
          </a>
        @endif
        <details class="fiscal-utilities" data-fiscal-utilities>
          <summary class="grid-tool grid-tool-wide" aria-label="Abrir utilitários fiscais">
            @include('partials.icon',['name'=>'layers','size'=>16])
            <span>Utilitários</span>
            @include('partials.icon',['name'=>'down','size'=>13])
          </summary>
          <div class="fiscal-utilities-dropdown">
            <button type="button" data-fiscal-download-xml disabled>
              @include('partials.icon',['name'=>'download','size'=>16])
              <span>Baixar XML</span>
            </button>
            <button type="button" data-fiscal-download-auxiliary disabled>
              @include('partials.icon',['name'=>'print','size'=>16])
              <span>{{ $tab==='nfce' ? 'Imprimir / salvar '.$auxiliaryLabel : 'Baixar '.$auxiliaryLabel }}</span>
            </button>
            @if($tab==='nfce')
              <button type="button" data-fiscal-cancel disabled title="Selecione uma NFC-e autorizada dentro do prazo de cancelamento">
                @include('partials.icon',['name'=>'x','size'=>16])
                <span>Cancelamento</span>
              </button>
              <a href="{{ route('fiscal.nfce.inutilizations') }}" title="Inutilizar uma faixa de numeração não utilizada na SEFAZ">
                @include('partials.icon',['name'=>'file-minus','size'=>16])
                <span>Inutilização</span>
              </a>
            @endif
          </div>
        </details>
      </div>

      <form id="fiscal-xml-download" action="{{ route('fiscal.utilities.xml') }}" method="post" hidden>
        @csrf
        <input type="hidden" name="document_type" value="{{ $tab }}">
      </form>

      <div class="bulk-actions">
        <select class="bulk-action-select" data-bulk-menu disabled aria-label="Ações em massa">
          <option value="">Ações em massa</option>
          <option value="local:export">Exportar selecionadas</option>
          <option value="local:print">Imprimir selecionadas</option>
        </select>
        <button class="bulk-apply" type="button" data-bulk-apply disabled>Aplicar</button>
      </div>
    </div>

    <div class="grid-actions-right">
      <a class="period-current" href="{{ route('fiscal.index',array_merge(request()->except('page','month'),['tab'=>$tab,'month'=>now()->format('Y-m')])) }}">Mês atual</a>
      <a class="period-arrow" href="{{ route('fiscal.index',array_merge(request()->except('page','month'),['tab'=>$tab,'month'=>$prevMonth])) }}" aria-label="Mês anterior">
        @include('partials.icon',['name'=>'chevron-left','size'=>19])
      </a>
      <span class="period-label">{{ $monthLabel }}</span>
      <a class="period-arrow" href="{{ route('fiscal.index',array_merge(request()->except('page','month'),['tab'=>$tab,'month'=>$nextMonth])) }}" aria-label="Próximo mês">
        @include('partials.icon',['name'=>'chevron','size'=>19])
      </a>
      <button class="grid-filter-button" type="button" data-filter-toggle="fiscal-filters" data-tooltip="Filtro avançado" aria-label="Filtro avançado">
        @include('partials.icon',['name'=>'search','size'=>18])
      </button>
    </div>
  </div>

  <div class="grid-filter-panel" id="fiscal-filters" @if(!$term && !$status && !$environment) hidden @endif>
    <form method="get" action="{{ route('fiscal.index') }}" class="toolbar-filters" data-live-search data-live-target="fiscal-live-results">
      <input type="hidden" name="tab" value="{{ $tab }}">
      <input type="hidden" name="month" value="{{ $month }}">

      <div class="table-search-group">
        <input type="search" autocomplete="off" name="search" value="{{ $term }}" placeholder="Número, venda, cliente, chave ou protocolo..." aria-label="Buscar documentos fiscais">
        <button type="submit" class="table-search-submit" data-tooltip="Pesquisar">
          @include('partials.icon',['name'=>'search','size'=>17])
        </button>
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
      <table class="cms-table">
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
            <th class="action-cell">Detalhes</th>
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
            $xmlAvailable=$fiscalUtilities->xmlPath($document)!==null;
            $auxiliaryAvailable=$fiscalUtilities->canOpenAuxiliary($document);
            $canCancelNfce=$tab==='nfce'
              && $document->status==='authorized'
              && !$document->cancellation_status
              && !$document->cancelled_at
              && $document->authorized_at
              && !$document->authorized_at->isFuture()
              && $document->authorized_at->copy()->addMinutes(30)->isFuture();
          @endphp
          <tr>
            <td class="select-cell">
              <input type="checkbox" data-row-select value="{{ $document->id }}"
                data-fiscal-xml="{{ $xmlAvailable ? '1' : '0' }}"
                data-fiscal-auxiliary-url="{{ $auxiliaryAvailable ? route('fiscal.utilities.auxiliary',$document) : '' }}"
                @if($tab==='nfce')
                  data-fiscal-cancellable="{{ $canCancelNfce ? '1' : '0' }}"
                  data-fiscal-cancel-url="{{ $canCancelNfce ? route('fiscal.nfce.cancel',$document) : '' }}"
                  data-fiscal-document-label="{{ ($document->series ?? '—').'/'.($document->document_number ?? '—') }}"
                @endif
                aria-label="Selecionar {{ $tabMeta['label'] }} {{ $document->document_number ?? $document->id }}">
            </td>
            <td>
              <a class="table-link" href="{{ route('fiscal.show',$document) }}">{{ $document->document_number ?? '—' }}</a>
            </td>
            <td>{{ $party }}</td>
            <td class="nowrap">{{ $document->series ?? '—' }}</td>
            <td>
              @if($operationCode!=='—')<span class="code-tag">{{ $operationCode }}</span>@else — @endif
            </td>
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
                @include('partials.icon',['name'=>'chevron','size'=>17])
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="10" class="empty-cell">Nenhum documento fiscal encontrado neste período.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    @include('partials.table-footer',['paginator'=>$documents])
  </div>
</section>

@if($tab==='nfce')
<dialog class="erp-dialog small-dialog" id="nfceCancelDialog" aria-labelledby="nfce-cancel-title">
  <form method="post" data-confirm-submit="Confirma o envio do cancelamento à SEFAZ? O evento autorizado não poderá ser desfeito."
    data-confirm-title="Confirmar cancelamento fiscal" data-confirm-kind="danger"
    data-confirm-label="Confirmar cancelamento">
    @csrf
    <div class="dialog-header">
      <div>
        <h2 id="nfce-cancel-title">Cancelamento de NFC-e</h2>
        <p data-fiscal-cancel-identification>NFC-e selecionada</p>
      </div>
      <button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">×</button>
    </div>
    <div class="dialog-body">
      <p>O cancelamento será solicitado à SEFAZ. A nota continuará autorizada até a confirmação do evento.</p>
      <label class="field wide">
        <span>Justificativa do cancelamento</span>
        <textarea name="reason" minlength="15" maxlength="255" rows="3" required
          placeholder="Informe o motivo (15 a 255 caracteres)"></textarea>
      </label>
      <label class="field wide">
        <span style="display:flex;align-items:center;gap:8px;font-weight:400">
          <input type="checkbox" name="no_circulation" value="1" required>
          Confirmo que a mercadoria não circulou.
        </span>
      </label>
    </div>
    <div class="dialog-footer">
      <button type="button" class="btn btn-secondary" data-dialog-close>Voltar</button>
      <button type="submit" class="btn btn-danger">Solicitar cancelamento</button>
    </div>
  </form>
</dialog>
@endif
@endsection
