@extends('layouts.app')
@section('title','Compras')
@section('description','Notas fiscais de entrada recebidas pela empresa.')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/fiscal-purchases.css') }}?v={{ filemtime(public_path('css/fiscal-purchases.css')) }}">
@endpush
@section('content')
@include('fiscal._nav')
<section class="cms-card sales-module-card fiscal-purchases-page">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <form method="post" action="{{ route('fiscal.purchases.sync') }}">
        @csrf
        <button class="grid-primary-action" type="submit">
          @include('partials.icon',['name'=>'refresh-cw','size'=>17]) <span>Buscar notas</span>
        </button>
      </form>
      <button type="button" class="grid-tool grid-tool-wide" data-dialog-open="purchaseXmlImport">
        @include('partials.icon',['name'=>'upload','size'=>17]) <span>Importar XML</span>
      </button>
    </div>
    <div class="grid-actions-right">
      <span class="fiscal-purchases-count">{{ $counts['total'] }} notas encontradas</span>
    </div>
  </div>
  <div class="fiscal-purchases-sync">
    <div class="fiscal-purchases-sync-main">
      <strong>Busca automática de notas recebidas</strong>
      <span>
        @if(!$ready)
          Configure o certificado A1 em Ajustes → Fiscal para consultar a SEFAZ.
        @elseif($state->last_checked_at)
          Última consulta: {{ $state->last_checked_at->format('d/m/Y H:i') }}.
          @if($state->next_check_at && $state->next_check_at->isFuture())
            Próxima consulta a partir de {{ $state->next_check_at->format('d/m H:i') }}.
          @endif
        @else
          Pronto para consultar as NF-e destinadas ao CNPJ cadastrado.
        @endif
      </span>
      @if($state->last_error)
        <span class="fiscal-purchases-warning">{{ $state->last_error }}</span>
      @endif
    </div>
    <form method="post" action="{{ route('fiscal.purchases.automatic') }}" class="fiscal-purchases-auto-form">
      @csrf
      <input type="hidden" name="auto_sync" value="{{ $state->auto_sync ? '0' : '1' }}">
      <button type="submit" class="{{ $state->auto_sync ? 'is-on':'' }}">
        {{ $state->auto_sync ? 'Busca automática ligada' : 'Ativar busca automática' }}
      </button>
    </form>
  </div>

  <div class="fiscal-purchases-filters">
    <form method="get" action="{{ route('fiscal.purchases.index') }}">
      <label>
        <span>Pesquisar nota ou fornecedor</span>
        <input type="search" name="search" value="{{ $search }}" placeholder="Fornecedor, CNPJ ou chave de acesso">
      </label>
      <label>
        <span>XML</span>
        <select name="status">
          <option value="">Todas as notas</option>
          <option value="summary" @selected($status==='summary')>Aguardando XML</option>
          <option value="xml_received" @selected($status==='xml_received')>XML disponível</option>
        </select>
      </label>
      <button class="btn btn-primary" type="submit">Filtrar</button>
      @if($search || $status)
        <a class="btn btn-light" href="{{ route('fiscal.purchases.index') }}">Limpar</a>
      @endif
    </form>
  </div>
  <div class="table-scroll">
    <table class="cms-table fiscal-purchases-table">
      <thead>
        <tr><th>Emissão</th><th>Fornecedor</th><th>Documento</th><th class="align-right">Valor</th><th>Situação</th><th>XML</th><th class="action-cell">Ação</th></tr>
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
        <td>{{ $invoice->xml_path ? 'Disponível':'Pendente' }}</td>
        <td class="action-cell"><a class="btn-icon" href="{{ route('fiscal.purchases.show',$invoice) }}" data-tooltip="Conferir nota">
          @include('partials.icon',['name'=>'eye','size'=>17])
        </a></td>
      </tr>
      @empty
      <tr><td colspan="7" class="empty-cell">
        <div class="empty-state compact">
          @include('partials.icon',['name'=>'file-search','size'=>23])
          <strong>Nenhuma NF-e de entrada encontrada</strong>
          <span>Use Buscar notas ou importe um XML recebido do fornecedor.</span>
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
      <p class="fiscal-purchases-help">O XML será conferido com o CNPJ da empresa. Não haverá movimentação automática de estoque ou financeiro.</p>
    </div>
    <div class="dialog-footer">
      <button class="btn btn-secondary" type="button" data-dialog-close>Cancelar</button>
      <button class="btn btn-primary" type="submit">Importar XML</button>
    </div>
  </form>
</dialog>
@endsection
