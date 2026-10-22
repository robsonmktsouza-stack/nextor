@extends('layouts.main')
@section('title','Automações')
@section('actions')
<form method="post" action="{{ route('automations.run') }}">@csrf
    <button class="btn primary" type="submit" data-confirm="Executar verificação agora?">
        @include('partials.icon',['name'=>'check','size'=>18])Executar verificação
    </button>
</form>
@if(app(\App\Services\ClientEmailNotificationService::class)->enabled() || app(\App\Services\InvoiceEmailNotificationService::class)->automaticEnabled())
<form method="post" action="{{ route('automations.send-emails') }}">@csrf
    <button class="btn subtle" type="submit" data-confirm="Enviar agora os avisos por e-mail que estão pendentes?">
        @include('partials.icon',['name'=>'send','size'=>18])Enviar e-mails pendentes
    </button>
</form>
@endif
@endsection
@section('content')
@php $hasFilters = request()->filled('q') || request()->filled('status'); @endphp
<div class="automation-overview" aria-label="Resumo das assinaturas e dos avisos">
    <div class="automation-overview-item"><strong>{{ $dueSoon }}</strong><span>Vencem em até 7 dias</span></div>
    <div class="automation-overview-item"><strong>{{ $expiredCount }}</strong><span>Licenças vencidas</span></div>
    <div class="automation-overview-item"><strong>{{ $offlineCount }}</strong><span>Sem comunicação há 2 dias</span></div>
    <div class="automation-overview-item"><strong>{{ $pendingEmails }}</strong><span>E-mails aguardando envio</span></div>
</div>
<section class="panel license-grid" data-admin-list data-grid-filename="automacoes-regras">
    @include('partials.listing.toolbar', [
        'toolbarUrl' => route('automations.index'),
        'createUrl' => route('automations.create'),
        'createLabel' => 'Nova automação',
        'createModal' => true,
        'filterId' => 'automation-rule-filters',
        'filtersActive' => $hasFilters,
    ])
    <div class="license-filters" id="automation-rule-filters" @unless($hasFilters) hidden @endunless>
        <form class="license-filter-form" method="get" action="{{ route('automations.index') }}">
            <label class="license-search"><span class="sr-only">Buscar regra</span>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar regra de automação...">
                @include('partials.icon',['name'=>'search','size'=>17])
            </label>
            <label><span class="sr-only">Situação</span><select name="status">
                <option value="">Todas</option>
                <option value="enabled" @selected(request('status')==='enabled')>Ativas</option>
                <option value="disabled" @selected(request('status')==='disabled')>Desativadas</option>
            </select></label>
            <input type="hidden" name="per_page" value="{{ $rules->perPage() }}">
            <button class="btn primary small" type="submit">Filtrar</button>
            @if($hasFilters)<a class="btn subtle small" href="{{ route('automations.index') }}">Limpar</a>@endif
        </form>
    </div>
    <div class="table-scroll">
        <table class="license-data-table" data-grid-table>
            <thead><tr><th>Regra</th><th>Descrição</th><th>Tipo</th><th>Prazo</th><th>Última verificação</th><th>Situação</th><th>E-mail</th><th class="license-actions-heading">Ações</th></tr></thead>
            <tbody>
                @forelse($rules as $rule)
                    <tr>
                        <td class="license-name-cell">
                            <strong class="license-name">{{ $rule->name }}</strong>
                            <form method="post" action="{{ route('automations.update',$rule) }}" id="automation-rule-{{ $rule->id }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="enabled" value="0">
                                <input type="hidden" name="name" value="{{ $rule->name }}">
                                <input type="hidden" name="description" value="{{ $rule->description }}">
                            </form>
                        </td>
                        <td>{{ $rule->description }}</td>
                        <td>{{ \App\Models\AutomationRule::KINDS[$rule->kindCode()] ?? 'Indisponível' }}</td>
                        <td class="license-nowrap">
                            @if($rule->kindCode() !== 'expired')
                                <label class="automation-inline-days"><span class="sr-only">Prazo em dias para {{ $rule->name }}</span>
                                    <input type="number" form="automation-rule-{{ $rule->id }}" name="days" min="{{ $rule->kindCode() === 'offline' ? 1 : 0 }}" max="365" value="{{ $rule->days }}" required>
                                    <span>dias</span>
                                </label>
                            @else
                                <input type="hidden" form="automation-rule-{{ $rule->id }}" name="days" value="0">
                                <span>Após vencimento</span>
                            @endif
                        </td>
                        <td class="license-nowrap">{{ $rule->last_run_at?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                        <td>
                            <label class="switch-row automation-table-switch">
                                <input form="automation-rule-{{ $rule->id }}" type="checkbox" name="enabled" role="switch"
                                    value="1" aria-label="Ativar a regra {{ $rule->name }}" @checked($rule->enabled)>
                                <span class="switch-track" aria-hidden="true"></span>
                                <span class="status {{ $rule->enabled ? 'active' : 'expired' }}">{{ $rule->enabled ? 'Ativa' : 'Desativada' }}</span>
                            </label>
                        </td>
                        <td><span class="status {{ $rule->notify_client_email ? 'active' : 'expired' }}">{{ $rule->notify_client_email ? 'Cliente' : 'Desligado' }}</span></td>
                        <td>
                            <div class="license-row-actions">
                                <button class="license-icon-action" type="submit" form="automation-rule-{{ $rule->id }}"
                                    data-tooltip="Salvar prazo e situação" aria-label="Salvar prazo e situação de {{ $rule->name }}">@include('partials.icon',['name'=>'check','size'=>16])</button>
                                <a class="license-icon-action" href="{{ route('automations.edit', $rule) }}"
                                   data-automation-open="edit"
                                   data-automation-action="{{ route('automations.update', $rule) }}"
                                   data-automation-rule-id="{{ $rule->id }}"
                                   data-automation-name="{{ $rule->name }}"
                                   data-automation-description="{{ $rule->description }}"
                                   data-automation-kind="{{ $rule->kindCode() }}"
                                   data-automation-days="{{ $rule->days }}"
                                   data-automation-enabled="{{ $rule->enabled ? '1' : '0' }}"
                                   data-automation-notify="{{ $rule->notify_client_email ? '1' : '0' }}"
                                   data-tooltip="Editar automação" aria-label="Editar automação {{ $rule->name }}">@include('partials.icon',['name'=>'edit','size'=>16])</a>
                            </div>
                        </td>
                    </tr>
                @empty<tr><td colspan="8" class="empty">Nenhuma regra encontrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', [
        'paginator' => $rules,
        'pageName' => 'rules_page',
        'singular' => 'regra',
        'plural' => 'regras',
    ])
</section>

<div class="automation-activity-tabs" role="tablist" aria-label="Histórico das notificações">
    <button type="button" class="automation-activity-tab" id="automationInternalTab"
        role="tab" aria-selected="true" aria-controls="automationInternalHistory"
        data-automation-history-tab="internal">Ocorrências</button>
    <button type="button" class="automation-activity-tab" id="automationEmailTab"
        role="tab" aria-selected="false" aria-controls="automationEmailHistory"
        data-automation-history-tab="email">E-mails para clientes</button>
</div>
<section id="automationInternalHistory" role="tabpanel" aria-labelledby="automationInternalTab"
    data-automation-history="internal" class="panel license-grid automation-history"
    data-admin-list data-grid-filename="automacoes-avisos">
    <div class="panel-heading admin-grid-heading"><h2>Últimos avisos automáticos</h2><a href="{{ route('history.index') }}">Ver histórico completo</a></div>
    @include('partials.listing.toolbar', ['showFilters' => false])
    <div class="table-scroll">
        <table class="license-data-table" data-grid-table>
            <thead><tr><th>Data</th><th>Cliente</th><th>Regra</th><th class="license-actions-heading">Ações</th></tr></thead>
            <tbody>
                @forelse($deliveries as $delivery)
                    <tr>
                        <td class="license-nowrap">{{ $delivery->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="license-name-cell">
                            @if($delivery->company)
                                <a class="license-name" href="{{ route('clients.show',$delivery->company_id) }}">{{ $delivery->company->legal_name }}</a>
                            @else
                                Conta indisponível
                            @endif
                        </td>
                        <td>{{ $delivery->rule?->name ?? '—' }}</td>
                        <td>
                            <div class="license-row-actions">
                                @if($delivery->company)
                                    <a class="license-icon-action" href="{{ route('clients.show',$delivery->company_id) }}" data-tooltip="Ver cliente" aria-label="Ver cliente">@include('partials.icon',['name'=>'clients','size'=>16])</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty<tr><td colspan="4" class="empty">Nenhum aviso registrado.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', [
        'paginator' => $deliveries,
        'pageName' => 'alerts_page',
        'singular' => 'aviso',
        'plural' => 'avisos',
    ])
</section>

<section id="automationEmailHistory" role="tabpanel" aria-labelledby="automationEmailTab"
    data-automation-history="email" hidden class="panel license-grid automation-history"
    data-admin-list data-grid-filename="automacoes-emails">
    <div class="panel-heading admin-grid-heading"><h2>E-mails para clientes</h2></div>
    @include('partials.listing.toolbar', ['showFilters' => false])
    <div class="table-scroll">
        <table class="license-data-table" data-grid-table>
            <thead><tr><th>Data</th><th>Cliente</th><th>Regra</th><th>Envio</th><th class="license-actions-heading">Ações</th></tr></thead>
            <tbody>
            @forelse($clientEmails as $clientEmail)
                <tr>
                    <td class="license-nowrap">{{ $clientEmail->sent_at?->format('d/m/Y H:i') ?? $clientEmail->created_at?->format('d/m/Y H:i') }}</td>
                    <td class="license-name-cell">{{ $clientEmail->company?->legal_name ?? 'Conta indisponível' }}</td>
                    <td>{{ $clientEmail->rule?->name ?? 'Regra indisponível' }}</td>
                    <td><span class="status {{ $clientEmail->status === 'sent' ? 'active' : 'expired' }}">{{ ['pending'=>'Pendente','processing'=>'Processando','retry'=>'Nova tentativa','sent'=>'Enviado','failed'=>'Falhou','skipped'=>'Cancelado'][$clientEmail->status] ?? 'Indisponível' }}</span></td>
                    <td><div class="license-row-actions">@if($clientEmail->company)
                        <a class="license-icon-action" href="{{ route('clients.show', $clientEmail->company) }}" data-tooltip="Ver cliente" aria-label="Ver cliente">
                            @include('partials.icon',['name'=>'clients','size'=>16])
                        </a>
                    @endif</div></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhum aviso por e-mail registrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', [
        'paginator' => $clientEmails,
        'pageName' => 'emails_page',
        'singular' => 'e-mail',
        'plural' => 'e-mails',
    ])
</section>
<dialog id="automationEditorDialog" class="account-dialog automation-editor-dialog" aria-labelledby="automationEditorTitle"
    data-reopen-context="{{ $errors->any() ? old('automation_context', '') : '' }}"
    data-old-name="{{ old('name', '') }}" data-old-description="{{ old('description', '') }}"
    data-old-kind="{{ old('kind', '') }}" data-old-days="{{ old('days', '') }}"
    data-old-enabled="{{ old('enabled', '0') }}"
    data-old-notify="{{ old('notify_client_email', '0') }}">
    <div class="dialog-header">
        <strong id="automationEditorTitle">Nova automação</strong>
        <button class="icon-button" type="button" data-automation-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>16])</button>
    </div>
    <form method="post" action="{{ route('automations.store') }}" data-automation-editor-form
        data-store-action="{{ route('automations.store') }}">
        @csrf
        <input type="hidden" name="_method" value="PUT" disabled>
        <input type="hidden" name="automation_context" value="create">
        <div class="dialog-body automation-editor-body">
            @include('automations.partials.fields', ['rule' => new \App\Models\AutomationRule()])
            <div class="control-form-actions">
                <button type="button" class="btn subtle" data-automation-close>Cancelar</button>
                <button type="submit" class="btn primary">@include('partials.icon',['name'=>'check','size'=>16])Salvar automação</button>
            </div>
        </div>
    </form>
</dialog>
@endsection
