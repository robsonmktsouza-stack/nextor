@extends('layouts.main')
@section('title','Faturas')
@section('content')
@php $hasFilters = request()->filled('q') || request()->filled('status') || request()->filled('company_id'); @endphp
<div class="automation-overview" aria-label="Resumo das faturas">
    <div class="automation-overview-item"><strong>{{ $summary['drafts'] }}</strong><span>Rascunhos</span></div>
    <div class="automation-overview-item"><strong>{{ $summary['outstanding'] }}</strong><span>Com saldo</span></div>
    <div class="automation-overview-item"><strong>{{ $summary['overdue'] }}</strong><span>Em atraso</span></div>
    <div class="automation-overview-item"><strong>{{ $summary['paid'] }}</strong><span>Quitadas</span></div>
</div>
<section class="panel license-grid" data-admin-list data-grid-filename="faturas">
    @include('partials.listing.toolbar', [
        'createUrl' => route('invoices.create'),
        'createLabel' => 'Nova fatura',
        'filterId' => 'invoice-filters',
        'filtersActive' => $hasFilters,
    ])
    <div class="license-filters" id="invoice-filters" @unless($hasFilters) hidden @endunless>
        <form class="license-filter-form" method="get" action="{{ route('invoices.index') }}">
            <label class="license-search"><span class="sr-only">Pesquisar fatura</span>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Número, cliente ou CNPJ">
                @include('partials.icon',['name'=>'search','size'=>17])
            </label>
            <label><span class="sr-only">Situação</span><select name="status">
                <option value="">Todas</option>
                @foreach(['draft'=>'Rascunho','open'=>'Em aberto','partial'=>'Parcial','overdue'=>'Vencida','paid'=>'Paga','cancelled'=>'Cancelada'] as $code=>$label)
                    <option value="{{ $code }}" @selected(request('status')===$code)>{{ $label }}</option>
                @endforeach
            </select></label>
            @if(request()->filled('company_id'))
                <input type="hidden" name="company_id" value="{{ (int) request('company_id') }}">
            @endif
            <input type="hidden" name="per_page" value="{{ $invoices->perPage() }}">
            <button type="submit" class="btn primary small">Filtrar</button>
            @if($hasFilters)<a href="{{ route('invoices.index') }}" class="btn subtle small">Limpar</a>@endif
        </form>
    </div>
    <div class="table-scroll">
        <table class="license-data-table" data-grid-table>
            <thead><tr>
                <th>Fatura</th><th>Cliente</th><th>Plano</th><th>Emissão</th><th>Vencimento</th>
                <th>Valor</th><th>Saldo</th><th>Situação</th><th class="license-actions-heading">Ações</th>
            </tr></thead>
            <tbody>
                @forelse($invoices as $invoice)
                    @php
                        $status = $invoice->effectiveStatus();
                        $badge = in_array($status,['paid'],true) ? 'active'
                            : (in_array($status,['cancelled'],true) ? 'suspended' : 'expired');
                    @endphp
                    <tr>
                        <td class="license-nowrap"><a class="license-name" href="{{ route('invoices.show',$invoice) }}">{{ $invoice->number }}</a></td>
                        <td class="license-name-cell"><strong class="license-name">{{ $invoice->company_name }}</strong>
                            <small>{{ $invoice->company_cnpj }}</small></td>
                        <td>{{ $invoice->plan_name }}</td>
                        <td class="license-nowrap">{{ $invoice->issue_date->format('d/m/Y') }}</td>
                        <td class="license-nowrap">{{ $invoice->due_date->format('d/m/Y') }}</td>
                        <td class="license-nowrap">{{ \App\Support\InvoiceMoney::brl($invoice->totalCents()) }}</td>
                        <td class="license-nowrap">{{ \App\Support\InvoiceMoney::brl($invoice->balanceCents()) }}</td>
                        <td><span class="status {{ $badge }}">{{ $invoice->statusLabel() }}</span></td>
                        <td><div class="license-row-actions">
                            <a class="license-icon-action" href="{{ route('invoices.show',$invoice) }}"
                               data-tooltip="Ver fatura" aria-label="Ver fatura {{ $invoice->number }}">
                                @include('partials.icon',['name'=>'receipt','size'=>16])
                            </a>
                            @if($status==='draft')
                                <a class="license-icon-action" href="{{ route('invoices.edit',$invoice) }}"
                                   data-tooltip="Editar fatura" aria-label="Editar fatura {{ $invoice->number }}">
                                    @include('partials.icon',['name'=>'edit','size'=>16])
                                </a>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="empty">Nenhuma fatura encontrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', ['paginator'=>$invoices,'singular'=>'fatura','plural'=>'faturas'])
</section>
@endsection
