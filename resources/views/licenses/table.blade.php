@php
    $listingUrl = route('licenses.index');
    $hasFilters = request()->filled('q') || request()->filled('status') || request()->filled('plan');
@endphp
<section class="panel license-grid" data-license-grid>
    <div class="license-grid-toolbar">
        <div class="license-grid-tools">
            <a href="{{ route('clients.create') }}" class="license-grid-new" title="Cadastrar empresa">
                <svg><use href="#i-plus"/></svg><span>Nova conta</span>
            </a>
            <div class="license-tool-group">
                <button class="license-tool" type="button" data-grid-print title="Imprimir listagem visível" aria-label="Imprimir listagem"><svg><use href="#i-print"/></svg></button>
                <button class="license-tool" type="button" data-grid-export title="Exportar página atual para CSV" aria-label="Exportar página atual"><svg><use href="#i-download"/></svg></button>
                <a class="license-tool" href="{{ request()->fullUrl() }}" title="Atualizar licenças" aria-label="Atualizar licenças"><svg><use href="#i-refresh"/></svg></a>
            </div>
        </div>
        <button class="license-tool license-filter-toggle" type="button"
            data-license-filter-toggle aria-controls="license-list-filters"
            aria-expanded="{{ $hasFilters ? 'true' : 'false' }}" title="Mostrar filtros"
            aria-label="Mostrar filtros"><svg><use href="#i-search"/></svg></button>
    </div>
    <div class="license-filters" id="license-list-filters" @unless($hasFilters) hidden @endunless>
        <form class="license-filter-form" method="get" action="{{ $listingUrl }}">
            <label class="license-search"><span class="sr-only">Buscar empresa</span><input type="search" name="q"
                placeholder="Buscar empresa, CNPJ ou subdomínio..." value="{{ request('q') }}">
                <svg><use href="#i-search"/></svg></label>
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
        <table class="license-data-table" data-license-export-table>
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
                                    title="Visualizar detalhes" aria-label="Visualizar detalhes de {{ $company->legal_name }}"><svg><use href="#i-clients"/></svg></button>
                                <a class="license-icon-action" href="{{ route('clients.show',$company) }}"
                                    title="Gerenciar licença" aria-label="Gerenciar licença de {{ $company->legal_name }}"><svg><use href="#i-edit"/></svg></a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">Nenhuma licença encontrada para os filtros informados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="license-grid-footer">
        <form method="get" action="{{ $listingUrl }}" class="license-page-size">
            <label><span class="sr-only">Quantidade de linhas por página</span>
                <select name="per_page" data-page-size>
                    @foreach([20, 50, 100] as $limit)
                        <option value="{{ $limit }}" @selected($companies->perPage()===$limit)>{{ $limit }} linhas por página</option>
                    @endforeach
                </select>
            </label>
            <input type="hidden" name="q" value="{{ request('q') }}">
            <input type="hidden" name="status" value="{{ request('status') }}">
            <input type="hidden" name="plan" value="{{ request('plan') }}">
        </form>
        <div class="license-page-controls">
            <span class="license-result-count">{{ $companies->total() }} {{ $companies->total()===1 ? 'licença' : 'licenças' }}</span>
            <a class="license-page-arrow {{ $companies->onFirstPage() ? 'disabled' : '' }}"
                href="{{ $companies->onFirstPage() ? '#' : $companies->url(1) }}"
                aria-label="Primeira página" @if($companies->onFirstPage()) aria-disabled="true" @endif>«</a>
            <a class="license-page-arrow {{ $companies->onFirstPage() ? 'disabled' : '' }}"
                href="{{ $companies->previousPageUrl() ?? '#' }}"
                aria-label="Página anterior" @if($companies->onFirstPage()) aria-disabled="true" @endif>‹</a>
            <span class="license-page-current">{{ $companies->currentPage() }} / {{ $companies->lastPage() }}</span>
            <a class="license-page-arrow {{ $companies->hasMorePages() ? '' : 'disabled' }}"
                href="{{ $companies->nextPageUrl() ?? '#' }}"
                aria-label="Próxima página" @unless($companies->hasMorePages()) aria-disabled="true" @endunless>›</a>
            <a class="license-page-arrow {{ $companies->hasMorePages() ? '' : 'disabled' }}"
                href="{{ $companies->hasMorePages() ? $companies->url($companies->lastPage()) : '#' }}"
                aria-label="Última página" @unless($companies->hasMorePages()) aria-disabled="true" @endunless>»</a>
        </div>
    </div>
</section>
