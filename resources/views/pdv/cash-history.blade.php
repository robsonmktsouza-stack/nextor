@extends('layouts.app')
@section('title','Histórico de caixas')
@section('actions')
<a class="btn btn-secondary" href="{{ route('pdv.index') }}">Voltar ao PDV</a>
@endsection
@push('styles')
<link rel="stylesheet" href="{{ asset('css/pdv-cash-admin.css') }}?v={{ filemtime(public_path('css/pdv-cash-admin.css')) }}">
<link rel="stylesheet" href="{{ asset('css/pdv-cash-report-modal.css') }}?v={{ filemtime(public_path('css/pdv-cash-report-modal.css')) }}">
@endpush
@section('content')
<section class="cms-card pdv-cash-history">
<div class="table-scroll"><table class="cms-table"><thead><tr><th>Turno</th><th>Caixa físico</th><th>Operador</th><th>Abertura</th><th>Fechamento</th>
<th class="pdv-cash-number nowrap">Esperado</th><th class="pdv-cash-number nowrap">Contado</th><th class="pdv-cash-number nowrap">Diferença</th><th>Situação</th></tr></thead>
<tbody>
@forelse($sessions as $session)
<tr>
<td><a href="{{ route('pdv.cash.report',$session) }}" data-cash-report-open
    data-cash-report-url="{{ route('pdv.cash.report',['cashSession'=>$session,'embed'=>1]) }}">#{{ $session->id }}</a></td>
<td>{{ $session->register?->name ?? 'Caixa não informado' }}</td>
<td>{{ $session->user?->name ?? '—' }}</td><td>{{ $session->opened_at?->format('d/m/Y H:i') }}</td>
<td>{{ $session->closed_at?->format('d/m/Y H:i') ?? 'Aberto' }}</td>
<td class="pdv-cash-number price-strong nowrap">{{ $session->expected_amount===null ? '—' : number_format((float)$session->expected_amount,2,',','.') }}</td>
<td class="pdv-cash-number price-strong nowrap">{{ $session->closing_amount===null ? '—' : number_format((float)$session->closing_amount,2,',','.') }}</td>
<td class="pdv-cash-number price-strong nowrap">{{ $session->difference_amount===null ? '—' : number_format((float)$session->difference_amount,2,',','.') }}</td>
<td>{{ !$session->closed_at ? 'Em operação' : ($session->reviewed_at ? 'Conferido' : ((float)$session->difference_amount!==0.0 ? 'Aguardando conferência' : 'Fechado')) }}</td>
</tr>
@empty
<tr><td colspan="9" class="empty-cell">Nenhum turno registrado.</td></tr>
@endforelse
</tbody></table></div><div class="table-footerbar">{{ $sessions->links('partials.pagination') }}</div>

</section>

@include('pdv.partials.cash-report-modal', [
  'autoCashReportId'=>0,
  'cashReportBackLabel'=>'Voltar ao histórico',
])
<script defer src="{{ asset('js/pdv-cash-report-modal.js') }}?v={{ filemtime(public_path('js/pdv-cash-report-modal.js')) }}"></script>

@endsection
