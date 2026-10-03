@extends('layouts.app')
@section('title','Rascunhos de NF-e')
@section('titleMeta'){{ $drafts->total() }} {{ $drafts->total()===1?'rascunho':'rascunhos' }}@endsection
@section('content')
@include('fiscal._nav',['tab'=>'nfe','tabs'=>[
  'nfe'=>['label'=>'NF-e'],'nfse'=>['label'=>'NFS-e'],'cte'=>['label'=>'CT-e'],'nfce'=>['label'=>'NFC-e']
]])

<section class="cms-card sales-module-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <a class="grid-primary-action" href="{{ route('fiscal.nfe.create') }}">@include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span></a>
      <div class="grid-tool-group">
        <a class="grid-tool grid-tool-wide" href="{{ route('fiscal.nfe.natures.index') }}" data-tooltip="Naturezas de Operação">@include('partials.icon',['name'=>'tag','size'=>17]) <span>Naturezas</span></a>
      </div>
    </div>
    <div class="grid-actions-right">
      <button class="grid-filter-button" type="button" data-filter-toggle="nfe-draft-filters" aria-label="Filtrar">@include('partials.icon',['name'=>'search','size'=>18])</button>
    </div>
  </div>

  <div class="grid-filter-panel" id="nfe-draft-filters" @if(!$term) hidden @endif>
    <form method="get" action="{{ route('fiscal.nfe.drafts.index') }}" class="toolbar-filters">
      <div class="table-search-group">
        <input type="search" name="search" value="{{ $term }}" placeholder="Número do rascunho, destinatário ou natureza...">
        <button class="table-search-submit">@include('partials.icon',['name'=>'search','size'=>17])</button>
      </div>
      <button class="btn btn-secondary">Filtrar</button>
      @if($term)<a class="btn btn-light" href="{{ route('fiscal.nfe.drafts.index') }}">Limpar</a>@endif
    </form>
  </div>

  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr><th>Rascunho</th><th>Destinatário</th><th>Natureza</th><th>Emissão</th><th>Total</th><th>Validação</th><th>Responsável</th><th class="action-cell">Detalhes</th></tr></thead>
      <tbody>
      @forelse($drafts as $draft)
        <tr>
          <td><a class="table-link" href="{{ route('fiscal.nfe.edit',$draft) }}">#{{ str_pad((string)$draft->id,5,'0',STR_PAD_LEFT) }}</a></td>
          <td>{{ $draft->customer?->name ?? data_get($draft->recipient_snapshot,'name','—') }}</td>
          <td>{{ $draft->operationNature?->name ?? '—' }}</td>
          <td class="nowrap">{{ $draft->issue_date?->format(AppModelsAppSetting::dateFormat()) ?? '—' }}</td>
          <td class="price-strong nowrap">R$ {{ number_format((float)data_get($draft->totals,'total',0),2,',','.') }}</td>
          <td><span class="status {{ $draft->validated_at?'status-ok':'status-muted' }}">{{ $draft->validated_at?'Validado':'Pendente' }}</span></td>
          <td>{{ $draft->user?->name ?? '—' }}</td>
          <td class="action-cell"><a class="btn-icon" href="{{ route('fiscal.nfe.edit',$draft) }}" data-tooltip="Abrir rascunho">@include('partials.icon',['name'=>'chevron','size'=>17])</a></td>
        </tr>
      @empty
        <tr><td colspan="8" class="empty-cell">Nenhum rascunho de NF-e salvo.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @include('partials.table-footer',['paginator'=>$drafts])
</section>
@endsection
