@extends('layouts.main')
@section('title','Planos')
@section('actions')<a href="{{ route('plans.create') }}" class="btn primary"><svg><use href="#i-plus"/></svg>Novo plano</a>@endsection
@section('content')
<section class="panel">
<div class="panel-heading"><h2>Catálogo de planos</h2><span class="panel-description">Defina nomes, módulos e preços de referência sem alterar o código do sistema.</span></div>
<div class="table-scroll"><table class="data-table plans-table">
<thead><tr><th>Código</th><th>Plano</th><th>Preço informado</th><th>Período</th><th>Módulos padrão</th><th>Clientes</th><th>Situação</th><th class="right">Ações</th></tr></thead>
<tbody>
@forelse($plans as $plan)
<tr>
<td class="mono">{{ $plan->code }}</td>
<td><strong>{{ $plan->name }}</strong>@if($plan->description)<small class="cell-secondary">{{ $plan->description }}</small>@endif</td>
<td>{{ $plan->monthly_price === null ? 'Não definido' : 'R$ '.number_format((float)$plan->monthly_price,2,',','.') }}</td>
<td>{{ $plan->period_months }} {{ $plan->period_months === 1 ? 'mês' : 'meses' }}</td>
<td>{{ collect($plan->modules)->map(fn($code) => config('license.modules')[$code] ?? $code)->join(' · ') }}</td>
<td>{{ $usage[$plan->code] ?? 0 }}</td>
<td><span class="status {{ $plan->active ? 'active' : 'expired' }}">{{ $plan->active ? 'Disponível' : 'Arquivado' }}</span></td>
<td class="right"><a class="btn subtle small" href="{{ route('plans.edit',$plan) }}">Editar</a></td>
</tr>
@empty<tr><td colspan="8" class="empty">Nenhum plano cadastrado.</td></tr>@endforelse
</tbody></table></div>
</section>
<div class="info-line more-space">Ao alterar um plano, os contratos já cadastrados permanecem com os módulos e a validade definidos na licença deles. O preço é apenas uma referência administrativa; não gera cobranças.</div>
@endsection
