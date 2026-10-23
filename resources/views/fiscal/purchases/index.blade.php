@extends('layouts.app')
@section('title','Fiscal')
@section('description','Notas fiscais de entrada recebidas pela empresa.')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/fiscal-purchases.css') }}?v={{ filemtime(public_path('css/fiscal-purchases.css')) }}">
@endpush
@push('scripts')
<script defer src="{{ asset('js/fiscal-purchases-manifest.js') }}?v={{ filemtime(public_path('js/fiscal-purchases-manifest.js')) }}"></script>
@endpush
@section('content')
@include('fiscal._nav')
<section class="cms-card sales-module-card fiscal-purchases-page">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <form method="post" action="{{ route('fiscal.purchases.sync') }}">
        @csrf
        <button class="grid-primary-action" type="submit" data-tooltip="Consultar notas recebidas na SEFAZ">
          @include('partials.icon',['name'=>'refresh','size'=>17]) <span>Buscar notas</span>
        </button>
      </form>
      <div class="grid-tool-group">
        <button type="button" class="grid-tool grid-tool-wide" data-dialog-open="purchaseXmlImport" data-tooltip="Importar XML da NF-e">
          @include('partials.icon',['name'=>'upload','size'=>17]) <span>Importar XML</span>
        </button>
        <button class="grid-tool" type="button" data-refresh-page data-tooltip="Atualizar listagem" aria-label="Atualizar listagem">
          @include('partials.icon',['name'=>'refresh','size'=>17])
        </button>
      </div>
    </div>
    <div class="grid-actions-right">
      <span class="fiscal-purchases-count">{{ $counts['total'] }} {{ $counts['total'] === 1 ? 'nota' : 'notas' }}</span>
      <form method="post" action="{{ route('fiscal.purchases.automatic') }}" class="fiscal-purchases-auto-form">
        @csrf
        <input type="hidden" name="auto_sync" value="{{ $state->auto_sync ? '0' : '1' }}">
        <button type="submit" class="fiscal-purchases-switch-button" role="switch"
          aria-checked="{{ $state->auto_sync ? 'true' : 'false' }}"
          aria-label="{{ $state->auto_sync ? 'Desativar busca automática' : 'Ativar busca automática' }}"
          @if($state->last_checked_at) data-tooltip="Última consulta: {{ $state->last_checked_at->format('d/m/Y H:i') }}" @endif>
          <span class="fiscal-purchases-switch-label">Busca automática</span>
          <span class="fiscal-purchases-switch-track" aria-hidden="true"><span class="fiscal-purchases-switch-thumb"></span></span>
        </button>
      </form>
      <button class="grid-filter-button" type="button" data-filter-toggle="purchases-filters" aria-controls="purchases-filters" data-tooltip="Filtrar compras" aria-label="Filtrar compras">
        @include('partials.icon',['name'=>'search','size'=>18])
      </button>
    </div>
  </div>
  @if($state->last_error)
    <div class="fiscal-purchases-notice" role="status">
      @include('partials.icon',['name'=>'alert','size'=>16])
      <span>{{ $state->last_error }}</span>
    </div>
  @endif
  <div class="grid-filter-panel" id="purchases-filters" @if(!$search && !$status) hidden @endif>
    <form method="get" action="{{ route('fiscal.purchases.index') }}" class="toolbar-filters fiscal-purchases-toolbar-filters">
      <div class="table-search-group">
        <input type="search" name="search" value="{{ $search }}" autocomplete="off"
          placeholder="Fornecedor, CNPJ ou chave de acesso" aria-label="Pesquisar nota ou fornecedor">
        <button type="submit" class="table-search-submit" data-tooltip="Pesquisar" aria-label="Pesquisar">
          @include('partials.icon',['name'=>'search','size'=>17])
        </button>
      </div>
      <select name="status" class="input-filter" aria-label="Filtrar disponibilidade do XML">
        <option value="">Todas as notas</option>
        <option value="summary" @selected($status==='summary')>Aguardando XML</option>
        <option value="xml_received" @selected($status==='xml_received')>XML disponível</option>
      </select>
      <button class="btn btn-secondary" type="submit">Filtrar</button>
      @if($search || $status)
        <a class="btn btn-light" href="{{ route('fiscal.purchases.index') }}">Limpar</a>
      @endif
    </form>
  </div>
  <div class="table-scroll">
    <table class="cms-table fiscal-purchases-table">
      <thead>
        <tr><th>Emissão</th><th>Fornecedor</th><th>Documento</th><th class="align-right">Valor</th><th>Situação</th><th>Manifesto</th><th>XML</th><th class="action-cell">Ação</th></tr>
      </thead>
      <tbody>
      @forelse($invoices as $invoice)
      <tr>
        <td class="nowrap">{{ $invoice->issued_at?->format('d/m/Y') ?? '—' }}</td>
        <td><strong>{{ $invoice->supplier_name ?: 'Fornecedor não identificado' }}</strong>
          <small>{{ $invoice->supplier_cnpj ?: 'CNPJ não informado' }}</small>
        </td>
        <td><span class="fiscal-purchases-key">{{ substr($invoice->access_key,0,16) }}…{{ substr($invoice->access_key,-6) }}</span></td>
        <td class="price-strong align-right nowrap">{{ $invoice->total!==null ? 'R$ '.number_format((float)$invoice->total,2,',','.') : '—' }}</td>
        <td><span class="status {{ $invoice->status==='xml_received' ? 'status-ok':'status-muted' }}">{{ $invoice->status==='xml_received' ? 'Para conferência':'Resumo recebido' }}</span></td>
        <td class="fiscal-manifest-cell">
          @php
            $lastAttempt=$invoice->latestManifestationAttempt;
            $needsReview=$lastAttempt && in_array($lastAttempt->status,['pending','uncertain'],true);
            $manifestLabel=$manifestationEvents[$invoice->manifestation] ?? 'Sem manifesto';
          @endphp
          <select class="fiscal-manifest-select" data-no-search data-manifest-select
            data-manifest-url="{{ route('fiscal.purchases.manifest',$invoice) }}"
            data-invoice-key="{{ $invoice->access_key }}"
            data-issued-date="{{ $invoice->issued_at?->format('Y-m-d') }}"
            aria-label="Manifestação da nota {{ $invoice->access_key }}"
            @disabled($needsReview)
            @if($needsReview) title="Envio anterior sem confirmação; consulte a SEFAZ" @endif>
            <option value="">{{ $needsReview ? 'Verificar na SEFAZ' : $manifestLabel }}</option>
            @unless($needsReview)
              @foreach($manifestationEvents as $eventCode=>$eventLabel)
                <option value="{{ $eventCode }}" @disabled($invoice->manifestation===$eventCode)>{{ $eventLabel }}</option>
              @endforeach
            @endunless
          </select>
          @if($needsReview)
            <form method="post" action="{{ route('fiscal.purchases.manifest.verify',$invoice) }}" class="fiscal-manifest-verify">
              @csrf
              <button type="submit" class="fiscal-manifest-verify-button" data-tooltip="Consulta fiscal sem reenviar o evento">
                @include('partials.icon',['name'=>'search','size'=>13]) <span>Conferir</span>
              </button>
            </form>
          @endif
          @if($invoice->manifestation_protocol)
            <span class="fiscal-manifest-protocol" title="Protocolo de manifestação">Prot. {{ $invoice->manifestation_protocol }}</span>
          @endif
        </td>
        <td>{{ $invoice->xml_path ? 'Disponível':'Pendente' }}</td>
        <td class="action-cell"><a class="btn-icon" href="{{ route('fiscal.purchases.show',$invoice) }}" data-tooltip="Conferir nota" aria-label="Conferir nota">
          @include('partials.icon',['name'=>'eye','size'=>17])
        </a></td>
      </tr>
      @empty
      <tr><td colspan="8" class="empty-cell">
        <div class="fiscal-empty-state" role="status">
          @include('partials.icon',['name'=>'receipt','size'=>23])
          <strong>Nenhuma NF-e de entrada encontrada</strong>
          <span>Consulte a SEFAZ ou importe um XML.</span>
        </div>
      </td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @include('partials.table-footer',['paginator'=>$invoices])
</section>

<dialog class="erp-dialog small-dialog" id="purchaseXmlImport" aria-labelledby="purchasesImportTitle">
  <form method="post" action="{{ route('fiscal.purchases.import') }}" enctype="multipart/form-data">
    @csrf
    <div class="dialog-header">
      <div><h2 id="purchasesImportTitle">Importar XML de compra</h2></div>
      <button class="close-dialog" type="button" data-dialog-close aria-label="Fechar">×</button>
    </div>
    <div class="dialog-body">
      <label class="field wide">
        <span>Arquivo XML da NF-e *</span>
        <input type="file" name="xml" accept=".xml,application/xml,text/xml" required>
      </label>
    </div>
    <div class="dialog-footer">
      <button class="btn btn-secondary" type="button" data-dialog-close>Cancelar</button>
      <button class="btn btn-primary" type="submit">Importar XML</button>
    </div>
  </form>
</dialog>
<dialog class="erp-dialog small-dialog" id="purchaseManifestDialog" aria-labelledby="purchaseManifestTitle">
  <form method="post" id="purchaseManifestForm">
    @csrf
    <input type="hidden" name="event_type" id="purchaseManifestType">
    <div class="dialog-header">
      <div><h2 id="purchaseManifestTitle">Manifestação do destinatário</h2></div>
      <button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">×</button>
    </div>
    <div class="dialog-body">
      <strong id="purchaseManifestEvent"></strong>
      <p class="fiscal-manifest-key" id="purchaseManifestKey"></p>
      <label class="field wide" id="purchaseManifestReasonBox" hidden>
        <span>Justificativa da operação não realizada</span>
        <textarea name="reason" id="purchaseManifestReason" minlength="15" maxlength="255" rows="3" placeholder="Descreva por que a operação não ocorreu"></textarea>
      </label>
      <p class="fiscal-manifest-confirmation" id="purchaseManifestWarning"></p>
    </div>
    <div class="dialog-footer">
      <button class="btn btn-secondary" type="button" data-dialog-close>Cancelar</button>
      <button class="btn btn-primary" type="submit">Enviar à SEFAZ</button>
    </div>
  </form>
</dialog>
@endsection
