@extends('layouts.app')
@section('title','Configurações fiscais')
@section('titleMeta', '')
@section('actions')
  <a href="{{ route('settings.index',['tab'=>'tax']) }}" class="btn btn-secondary">
    @include('partials.icon',['name'=>'arrow-left','size'=>16]) Impostos e tributação
  </a>
@endsection
@section('content')
@push('styles')
<style>
.catalog-filterbar{display:grid;grid-template-columns:minmax(135px,1.1fr) minmax(200px,2fr) minmax(145px,1fr) 74px minmax(135px,1fr) minmax(135px,1fr) auto;gap:10px;align-items:end;padding:12px;border-bottom:1px solid var(--border,#d8e0e7);background:var(--panel,#fff)}
.catalog-filterbar .field{min-width:0;margin:0}.catalog-filterbar .field input,.catalog-filterbar .field select{width:100%;min-width:0}.catalog-filterbar .field>span{display:block;white-space:nowrap}.catalog-filter-submit{height:36px;white-space:nowrap}.catalog-filterbar+.table-scroll{margin-top:0}.catalog-filterbar~.erp-help-details{margin:12px}.catalog-row-details{max-width:260px}.catalog-row-details summary{cursor:pointer;white-space:nowrap}.catalog-row-detail-body{max-height:180px;overflow:auto;padding:7px 0;line-height:1.45;overflow-wrap:anywhere}.catalog-row-detail-body p{margin:0 0 7px}.catalog-row-detail-body a{display:inline-block;margin-top:4px}
@media(max-width:1250px){.catalog-filterbar{grid-template-columns:repeat(3,minmax(0,1fr))}.catalog-filter-search{grid-column:span 2}}
@media(max-width:700px){.catalog-filterbar{grid-template-columns:repeat(2,minmax(0,1fr))}.catalog-filter-search{grid-column:span 2}}
</style>
@endpush
<nav class="sales-module-tabs" aria-label="Configurações fiscais">
  <a href="{{ route('fiscal.catalog.index') }}" class="{{ $tab==='entries'?'active':'' }}">Tabelas oficiais</a>
  <a href="{{ route('fiscal.catalog.index',['tab'=>'coverage']) }}" class="{{ $tab==='coverage'?'active':'' }}">Consulta por estado</a>
  <a href="{{ route('fiscal.nfe.natures.index') }}">Naturezas de operação</a>
  <a href="{{ route('fiscal.tax-groups.index') }}">Grupos tributários</a>
  <a href="{{ route('fiscal.tax-rules.index') }}">Regras tributárias</a>
  <a href="{{ route('fiscal.fcp.index') }}">FCP</a>
  <a href="{{ route('fiscal.catalog.index',['tab'=>'history']) }}" class="{{ $tab==='history'?'active':'' }}">Atualizações</a>
</nav>
@if($tab==='entries')
<section class="cms-card">
  <form method="get" action="{{ route('fiscal.catalog.index') }}" class="catalog-filterbar">
    <label class="field"><span>Tabela</span><select name="kind">@foreach($kinds as $value=>$label)<option value="{{ $value }}" @selected($kind===$value)>{{ $label }}</option>@endforeach</select></label>
    <label class="field catalog-filter-search"><span>Código ou descrição</span><input name="q" value="{{ request('q') }}" maxlength="120" placeholder="Pesquisar código ou descrição"></label>
    <label class="field"><span>Data da operação</span><input name="date" type="date" value="{{ request('date') }}"></label>
    <label class="field catalog-filter-uf"><span>UF</span><input name="uf" value="{{ request('uf') }}" maxlength="2" pattern="[A-Za-z]{2}" placeholder="Todas"></label>
    <label class="field"><span>Situação</span><select name="status"><option value="">Todas</option><option value="reference" @selected(request('status')==='reference')>Referência</option><option value="template" @selected(request('status')==='template')>Modelo</option><option value="revoked" @selected(request('status')==='revoked')>Revogada</option></select></label>
    <label class="field"><span>Referência</span><select name="release"><option value="">Atual</option>@foreach($releases as $item)<option value="{{ $item->id }}" @selected((string)request('release')===(string)$item->id)>{{ $item->version }}</option>@endforeach</select></label>
    <button class="btn btn-secondary catalog-filter-submit" type="submit">@include('partials.icon',['name'=>'search','size'=>15]) Filtrar</button>
  </form>
  <div class="table-scroll"><table class="cms-table">
    <thead><tr><th>Código</th><th>Descrição</th><th>UF</th><th>Validade</th><th>Situação</th><th>Detalhes</th></tr></thead>
    <tbody>@forelse($entries as $entry)
      <tr>
        <td><strong>{{ $entry->code }}</strong></td>
        <td>{{ $entry->description }}</td>
        <td>{{ $entry->uf ?: 'Todas' }}</td>
        <td>{{ $entry->valid_until?->format('d/m/Y') ?? 'Sem prazo definido' }}</td>
        <td><span class="status status-muted">{{ ['reference'=>'Referência','template'=>'Não habilitado','revoked'=>'Revogada'][$entry->status] ?? $entry->status }}</span></td>
        <td>
          @if(data_get($entry->source,'url'))
            <a class="table-link" href="{{ data_get($entry->source,'url') }}" target="_blank" rel="noopener noreferrer">Consultar legislação</a>
          @else
            <span class="table-subtitle">—</span>
          @endif
        </td>
      </tr>
    @empty<tr><td colspan="6" class="empty-cell">Nenhum registro encontrado para esta tabela e versão.</td></tr>@endforelse</tbody>
  </table></div>
  @include('partials.table-footer',['paginator'=>$entries])

</section>
@elseif($tab==='coverage')
<section class="cms-card">
  <form method="get" action="{{ route('fiscal.catalog.index') }}" class="settings-editor">
    <input type="hidden" name="tab" value="coverage">
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-4"><span>Versão consultada</span><select name="release"><option value="">Atual</option>@foreach($releases as $item)<option value="{{ $item->id }}" @selected($release?->id===$item->id)>{{ $item->version }}</option>@endforeach</select></label>
      <label class="field col-3"><span>Data de referência</span><input type="date" name="date" value="{{ $coverageDate }}" required></label>
      <div class="field col-2"><span>&nbsp;</span><button class="btn btn-secondary" type="submit">Consultar</button></div>
    </div>
  </form>
  @php($coverageLabels=['complete'=>'Completa','partial'=>'Parcial','not_bundled'=>'Sem tabela validada','unavailable'=>'Fonte indisponível'])
  <div class="table-scroll"><table class="cms-table">
    <thead><tr><th>UF</th><th>NCM</th><th>CFOP</th><th>CEST</th><th>cBenef</th><th>Observação</th></tr></thead>
    <tbody>@foreach($coverageByUf as $state=>$domains)<tr>
      <td><strong>{{ $state }}</strong></td>
      @foreach(['ncm','cfop','cest','benefit'] as $domain)
        <td><a class="table-link" href="{{ route('fiscal.catalog.index',['release'=>$release?->id,'kind'=>$domain,'uf'=>$state,'date'=>$coverageDate]) }}">{{ $coverageLabels[$domains[$domain]['status']] ?? 'Sem tabela validada' }}</a></td>
      @endforeach
      <td><span class="table-subtitle">{{ ($domains['benefit']['status'] ?? '')==='complete' ? 'Consulte os benefícios disponíveis' : 'Consulte a situação da UF' }}</span></td>
    </tr>@endforeach</tbody>
  </table></div>

</section>
@else
<section class="cms-card">
  <div class="table-scroll"><table class="cms-table">
    <thead><tr><th>Referência</th><th>Consulta</th><th>Atualização</th><th>Registros</th><th>Detalhes</th></tr></thead>
    <tbody>@forelse($releases as $item)<tr>
      <td><a class="table-link" href="{{ route('fiscal.catalog.index',['release'=>$item->id]) }}">{{ $item->version }}</a>@if($current?->id===$item->id)<small class="table-subtitle">Atual</small>@endif</td>
      <td>{{ $item->consulted_at?->format('d/m/Y') }}</td><td>{{ $item->installed_at?->format('d/m/Y H:i') }}</td><td>{{ $item->entry_count }}</td>
      <td><details><summary class="table-link">Ver detalhes</summary>
        
        @foreach(($item->sources ?? []) as $source)<p>{{ $source['reference'] ?? $source['id'] ?? '' }}<br><a class="table-link" href="{{ $source['url'] ?? '' }}" target="_blank" rel="noopener noreferrer">{{ $source['url'] ?? '' }}</a></p>@endforeach
        
      </details></td>
    </tr>@empty<tr><td colspan="5" class="empty-cell">Nenhuma versão instalada.</td></tr>@endforelse</tbody>
  </table></div>
</section>
@endif
@endsection
