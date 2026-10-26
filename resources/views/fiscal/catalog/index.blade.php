@extends('layouts.app')
@section('title','Configurações fiscais')
@section('titleMeta', '')
@section('actions')
  <a href="{{ route('settings.index',['tab'=>'tax']) }}" class="btn btn-secondary">
    @include('partials.icon',['name'=>'arrow-left','size'=>16]) Impostos e tributação
  </a>
@endsection
@section('content')
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
    <label class="field"><span>Atualização consultada</span><select name="release"><option value="">Atual</option>@foreach($releases as $item)<option value="{{ $item->id }}" @selected((string)request('release')===(string)$item->id)>{{ $item->consulted_at?->format('d/m/Y') ?? $item->installed_at?->format('d/m/Y') ?? 'Data não informada' }}</option>@endforeach</select></label>
    <button class="btn btn-secondary catalog-filter-submit" type="submit">@include('partials.icon',['name'=>'search','size'=>15]) Filtrar</button>
  </form>
  <div class="table-scroll"><table class="cms-table">
    <thead><tr><th>Código</th><th>Descrição</th><th>UF</th><th>Validade</th><th>Situação</th><th>Detalhes</th></tr></thead>
    <tbody>@forelse($entries as $entry)
      <tr>
        <td class="nowrap"><strong>{{ $entry->code }}</strong></td>
        <td>{{ $entry->description }}</td>
        <td>{{ $entry->uf ?: 'Nacional' }}<small class="table-subtitle">{{ $entry->regime ? \App\Support\FiscalPresentation::value($entry->regime) : 'Conforme condições' }}</small></td>
        <td>{{ $entry->valid_from?->format('d/m/Y') ?? 'Não informada' }}<small class="table-subtitle">{{ $entry->valid_until ? 'até '.$entry->valid_until->format('d/m/Y') : 'Sem término informado' }}</small></td>
        <td><span class="status status-muted">{{ $entry->status==='revoked' ? 'Revogada' : ($entry->status==='template' || data_get($entry->parameters,'automatic_application')===false ? 'Indisponível para utilização' : 'Referência') }}</span></td>
        <td><details><summary class="table-link">Consultar</summary>
          <p>{{ data_get($entry->source,'reference') }}</p>
          @if(data_get($entry->source,'url'))<a class="table-link" href="{{ data_get($entry->source,'url') }}" target="_blank" rel="noopener noreferrer">Fonte oficial</a>@endif
          <small class="table-subtitle">Versão {{ data_get($entry->source,'version','não declarada') }} · Consulta {{ data_get($entry->source,'consulted_at',$release?->consulted_at?->format('Y-m-d')) }}</small>
          @if($entry->status==='template' || data_get($entry->parameters,'automatic_application')===false)<p>{{ $kind==='benefit' ? 'Benefício fiscal não disponível para utilização.' : 'Referência pendente de validação para utilização.' }}</p>@endif
          @if(data_get($entry->parameters,'requires_manual_ncm_review'))<p>O vínculo com o NCM precisa de revisão fiscal.</p>@endif
          @include('fiscal.partials.readable-values',['values'=>$entry->parameters ?? []])
        </details></td>
      </tr>
    @empty<tr><td colspan="6" class="empty-cell">Nenhum registro encontrado para os filtros selecionados.</td></tr>@endforelse</tbody>
  </table></div>
  @include('partials.table-footer',['paginator'=>$entries])

</section>
@elseif($tab==='coverage')
<section class="cms-card">
  <form method="get" action="{{ route('fiscal.catalog.index') }}" class="settings-editor">
    <input type="hidden" name="tab" value="coverage">
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-4"><span>Atualização consultada</span><select name="release"><option value="">Atual</option>@foreach($releases as $item)<option value="{{ $item->id }}" @selected($release?->id===$item->id)>{{ $item->consulted_at?->format('d/m/Y') ?? $item->installed_at?->format('d/m/Y') ?? 'Data não informada' }}</option>@endforeach</select></label>
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
      <td>@if(!empty($domains['benefit']['note']) || !empty($domains['benefit']['conditions']))<details><summary class="table-link">Consultar</summary>
        <p>{{ match($domains['benefit']['status']){'complete'=>'Consulte os códigos e confirme o enquadramento de cada benefício.','partial'=>'Alguns benefícios exigem conferência adicional com o responsável fiscal.',default=>'Benefícios fiscais não disponíveis para utilização automática nesta UF.'} }}</p>
      </details>@endif</td>
    </tr>@endforeach</tbody>
  </table></div>

</section>
@else
<section class="cms-card">
  <div class="table-scroll"><table class="cms-table">
    <thead><tr><th>Atualização</th><th>Consulta</th><th>Instalação</th><th>Registros</th><th>Fontes oficiais</th></tr></thead>
    <tbody>@forelse($releases as $item)<tr>
      <td><a class="table-link" href="{{ route('fiscal.catalog.index',['release'=>$item->id]) }}">{{ $item->consulted_at?->format('d/m/Y') ?? $item->installed_at?->format('d/m/Y') ?? 'Data não informada' }}</a>@if($current?->id===$item->id)<small class="table-subtitle">Atual</small>@endif</td>
      <td>{{ $item->consulted_at?->format('d/m/Y') }}</td><td>{{ $item->installed_at?->format('d/m/Y H:i') }}</td><td>{{ $item->entry_count }}</td>
      <td><details><summary class="table-link">Ver fontes</summary>
        @foreach(($item->sources ?? []) as $source)<p>{{ $source['reference'] ?? 'Fonte normativa' }}<br><a class="table-link" href="{{ $source['url'] ?? '' }}" target="_blank" rel="noopener noreferrer">Consultar fonte oficial</a></p>@endforeach
        <a class="table-link" href="{{ route('fiscal.catalog.index',['tab'=>'coverage','release'=>$item->id]) }}">Consultar disponibilidade por UF</a>
      </details></td>
    </tr>@empty<tr><td colspan="5" class="empty-cell">Nenhuma atualização instalada.</td></tr>@endforelse</tbody>
  </table></div>
</section>
@endif
@endsection
