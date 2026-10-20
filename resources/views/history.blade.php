@extends('layouts.main')
@section('title','Histórico')
@section('content')
<section class="panel">
<form method="get" action="{{ route('history.index') }}" class="filters"><label class="field-search"><span>Localizar atividades</span><span class="search-wrap"><svg><use href="#i-search"/></svg><input name="q" value="{{ request('q') }}" placeholder="Empresa ou subdomínio"></span></label><button class="btn primary">Buscar</button><a href="{{ route('history.index') }}" class="btn subtle">Limpar</a></form>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Data</th><th>Cliente</th><th>Operador</th><th>Evento</th><th>Descrição</th></tr></thead><tbody>
@forelse($events as $event)<tr><td class="mono">{{ $event->created_at?->format('d/m/Y H:i') }}</td><td><a href="{{ route('clients.show',$event->company_id) }}">{{ $event->company?->legal_name }}</a></td><td>{{ $event->user?->name??'Sistema' }}</td><td>{{ ucfirst(str_replace('_',' ',$event->event_type)) }}</td><td>{{ $event->description }}</td></tr>
@empty<tr><td colspan="5" class="empty">Nenhuma atividade encontrada.</td></tr>@endforelse
</tbody></table></div>
<div class="pagination"><span>Página {{ $events->currentPage() }} de {{ $events->lastPage() }}</span><div>@if($events->previousPageUrl())<a class="btn subtle small" href="{{ $events->previousPageUrl() }}">Anterior</a>@endif @if($events->nextPageUrl())<a class="btn subtle small" href="{{ $events->nextPageUrl() }}">Próxima</a>@endif</div></div>
</section>
@endsection
