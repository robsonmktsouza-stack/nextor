@php
    $listingUrl = $licenses ? route('licenses.index') : route('clients.index');
    $hasFilters = request()->filled('q') || request()->filled('status') || request()->filled('plan');
@endphp
<section class="panel license-grid" data-license-grid data-admin-list data-grid-filename="{{ $licenses ? 'licencas' : 'clientes' }}">
    @include('partials.listing.toolbar', [
        'toolbarUrl' => $listingUrl,
        'createUrl' => route('clients.create'),
        'createLabel' => 'Nova conta',
        'filterId' => 'license-list-filters',
        'filtersActive' => $hasFilters,
    ])
    <div class="license-filters" id="license-list-filters" @unless($hasFilters) hidden @endunless>
        <form class="license-filter-form" method="get" action="{{ $listingUrl }}">
            <label class="license-search"><span class="sr-only">Buscar empresa</span><input type="search" name="q"
                placeholder="Buscar empresa, CNPJ ou subdomínio..." value="{{ request('q') }}">
                @include('partials.icon',['name'=>'search','size'=>18])</label>
            <label><span class="sr-only">Situação</span><select name="status">
                <option value="">Todas as situações</option>
                <option value="active" @selected(request('status')==='active')>Ativas</option>
                <option value="suspended" @selected(request('status')==='suspended')>Suspensas</option>
                <option value="expired" @selected(request('status')==='expired')>Vencidas</option>
            </select></label>
            <label><span class="sr-only">Plano</span><select name="plan">
                <option value="">Todos os planos</option>
                @foreach($plans as $plan)
                    <option value="{{ $plan->code }}" @selected(request('plan')===$plan->code)>{{ $plan->name }}</option>
                @endforeach
            </select></label>
            <input type="hidden" name="per_page" value="{{ $companies->perPage() }}">
            <button class="btn primary small" type="submit">Filtrar</button>
            @if($hasFilters)<a class="btn subtle small" href="{{ $listingUrl }}">Limpar</a>@endif
        </form>
    </div>
    <div class="table-scroll">
        <table class="license-data-table" data-license-export-table data-grid-table>
            <thead><tr>
                <th>Código</th>
                <th>Empresa</th>
                <th>Subdomínio</th>
                <th>Plano</th>
                <th>Vencimento</th>
                <th>Situação</th>
                <th>Último contato</th>
                <th class="license-actions-heading">Ações</th>
            </tr></thead>
            <tbody>
                @forelse($companies as $company)
                    @php $status = $company->currentStatus(); @endphp
                    <tr>
                        <td><span class="license-code">#{{ str_pad($company->id,4,'0',STR_PAD_LEFT) }}</span></td>
                        <td class="license-name-cell">
                            <a class="license-name" href="{{ route('clients.show',$company) }}">{{ $company->legal_name }}</a>
                            <small>{{ substr($company->cnpj,0,2) }}.{{ substr($company->cnpj,2,3) }}.{{ substr($company->cnpj,5,3) }}/{{ substr($company->cnpj,8,4) }}-{{ substr($company->cnpj,12,2) }}</small>
                        </td>
                        <td class="license-subdomain">{{ $company->subdomain }}</td>
                        <td>{{ $plans->firstWhere('code',$company->plan)?->name ?? $company->plan }}</td>
                        <td class="license-nowrap">{{ $company->expires_at?->format('d/m/Y') ?? 'Sem prazo' }}</td>
                        <td>
                            <div class="license-status-control">
                                <span class="status {{ $status }}">{{ ['active'=>'Ativa','expired'=>'Vencida','suspended'=>'Suspensa'][$status] }}</span>
                                <form method="post" action="{{ route('clients.status',$company) }}" data-license-switch-form>
                                    @csrf
                                    <input type="hidden" name="action" value="{{ $company->license_status==='suspended' ? 'activate' : 'suspend' }}">
                                    <label class="switch-control switch-small"
                                        title="{{ $company->license_status === 'suspended' ? 'Ativar acesso comercial' : 'Suspender acesso comercial' }}">
                                        <input type="checkbox" role="switch" name="license_enabled"
                                            aria-label="{{ $company->license_status === 'suspended' ? 'Ativar' : 'Suspender' }} licença de {{ $company->legal_name }}"
                                            @checked($company->license_status !== 'suspended')
                                            data-license-switch>
                                        <span class="switch-track" aria-hidden="true"></span>
                                    </label>
                                </form>
                            </div>
                        </td>
                        <td class="license-nowrap">{{ $company->last_seen_at?->format('d/m/Y H:i') ?? 'Não conectada' }}</td>
                        <td>
                            <div class="license-row-actions">
                                <button type="button" class="license-icon-action" data-account-details="{{ route('clients.details',$company) }}"
                                    title="Visualizar detalhes" aria-label="Visualizar detalhes de {{ $company->legal_name }}">@include('partials.icon',['name'=>'clients','size'=>18])</button>
                                <a class="license-icon-action" href="{{ route('clients.show',$company) }}"
                                    title="Gerenciar licença" aria-label="Gerenciar licença de {{ $company->legal_name }}">@include('partials.icon',['name'=>'edit','size'=>16])</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">Nenhuma licença encontrada para os filtros informados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.listing.footer', [
        'paginator' => $companies,
        'footerUrl' => $listingUrl,
        'singular' => $licenses ? 'licença' : 'cliente',
        'plural' => $licenses ? 'licenças' : 'clientes',
    ])
</section>
