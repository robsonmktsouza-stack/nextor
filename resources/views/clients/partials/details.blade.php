<div class="client-overview">
 <div><h2>{{ $company->legal_name }}</h2><p>{{ $company->trade_name?:$company->subdomain }} · CNPJ {{ $company->cnpj }}</p></div>
 <span class="status {{ $company->currentStatus() }}">{{ ['active'=>'Ativa','expired'=>'Vencida','suspended'=>'Suspensa'][$company->currentStatus()] }}</span>
</div>
<div class="detail-grid">
<section class="detail-box"><h3>Dados do cliente</h3>
<form method="post" action="{{ route('clients.update',$company) }}">@csrf @method('PUT')
<div class="form-grid">
<label class="span-two">Razão social <input name="legal_name" required value="{{ $company->legal_name }}" maxlength="220"></label>
<label>Nome fantasia <input name="trade_name" value="{{ $company->trade_name }}" maxlength="220"></label>
<label>CNPJ <input value="{{ $company->cnpj }}" disabled></label>
<label>Responsável <input name="contact_name" value="{{ $company->contact_name }}" maxlength="160"></label>
<label>E-mail <input name="email" type="email" value="{{ $company->email }}" required></label>
<label>Telefone <input name="phone" value="{{ $company->phone }}" maxlength="30"></label>
<label>Login de referência <input name="admin_login" value="{{ $company->admin_login }}" maxlength="100"></label>
<label class="span-two">Endereço da instalação <input name="installation_url" type="url" value="{{ $company->installation_url }}" placeholder="https://..."></label>
</div><button type="submit" class="btn primary small">Salvar dados</button>
</form>
<div class="metadata">
@if($company->provisioningJob)
<strong>Preparação do ERP</strong><span>{{ match($company->provisioningJob->status) {
    'queued' => 'Aguardando preparação',
    'processing' => 'Preparando acesso',
    'ready' => 'Acesso preparado',
    'failed' => 'Preparação precisa de revisão',
    default => 'Aguardando preparação'
} }}</span>
@if(app()->environment('local') && $company->provisioningJob->status === 'ready')
    <strong>Acesso inicial</strong>
    <span><a href="{{ route('clients.first-access',$company) }}" target="_blank" rel="noopener noreferrer">Primeiro acesso ao ERP</a></span>
@endif
@endif
<strong>Identificador de acesso</strong><span>{{ $company->subdomain }} <small>(exclusivo e permanente)</small></span><strong>Conexão</strong><span><span class="status {{ $company->last_seen_at ? 'active' : 'expired' }}">{{ $company->last_seen_at ? 'Conectada anteriormente' : 'Aguardando instalação' }}</span></span><strong>Último contato</strong><span>{{ $company->last_seen_at?->format('d/m/Y H:i')??'Ainda não conectada' }}</span></div>
</section>
<section class="detail-box"><h3>Licença e acesso</h3>
<div class="invoice-client-shortcuts">
    <a href="{{ route('invoices.index',['company_id'=>$company->id]) }}" class="btn subtle small">@include('partials.icon',['name'=>'receipt','size'=>16])Ver faturas</a>
    <a href="{{ route('invoices.create',['company_id'=>$company->id]) }}" class="btn subtle small">@include('partials.icon',['name'=>'plus','size'=>16])Nova fatura</a>
</div>
<div class="license-summary"><div><span>Plano atual</span><strong>{{ $plans->firstWhere('code',$company->plan)?->name ?? $company->plan }}</strong></div><div><span>Vencimento</span><strong>{{ $company->expires_at?->format('d/m/Y')??'Sem prazo' }}</strong></div></div>
<form method="post" action="{{ route('clients.status',$company) }}" class="status-controls switch-status-controls" data-license-switch-form>
@csrf
<input type="hidden" name="action" value="{{ $company->license_status === 'suspended' ? 'activate' : 'suspend' }}">
<label class="switch-row switch-status">
<input type="checkbox" name="license_enabled" role="switch" data-license-switch
aria-label="Ativar ou suspender licença de {{ $company->legal_name }}"
@checked($company->license_status !== 'suspended')>
<span class="switch-track" aria-hidden="true"></span>
<span>Acesso comercial {{ $company->license_status === 'suspended' ? 'suspenso' : 'liberado' }}</span>
</label>

</form>
<form method="post" action="{{ route('clients.renew',$company) }}" class="renew-controls">@csrf
<label>Renovar por<select name="months"><option value="1">1 mês</option><option value="3">3 meses</option><option value="6">6 meses</option><option value="12">12 meses</option></select></label><button class="btn primary" type="submit">@include('partials.icon',['name'=>'calendar','size'=>18])Renovar</button>
</form>
<form method="post" action="{{ route('clients.modules',$company) }}">@csrf
<div class="form-grid"><label>Plano<select name="plan" data-plan-selector>@foreach($plans as $plan)<option value="{{ $plan->code }}" data-modules="{{ implode(',', $plan->modules ?? []) }}" @selected($company->plan===$plan->code)>{{ $plan->name }}{{ $plan->active ? '' : ' (arquivado)' }}</option>@endforeach</select></label><label>Validade<input type="date" name="expires_at" value="{{ $company->expires_at?->format('Y-m-d') }}"></label></div>
<div class="plan-modules-overview"><strong>Inclui</strong><span data-plan-modules-summary>{{ collect($plans->firstWhere('code',$company->plan)?->modules ?? [])->map(fn($code) => config('license.modules')[$code] ?? $code)->join(' · ') ?: 'Nenhum módulo' }}</span></div>
<button type="submit" class="btn primary small">Salvar licença</button></form>
</section>
<section class="detail-box"><h3>Anotações e eventos</h3>
<div class="events-stream">
@forelse($company->events as $event)
<div class="event"><div><strong>{{ $event->user?->name??'Sistema' }}</strong><small>{{ $event->created_at?->format('d/m/Y H:i') }}</small></div><p>{{ \App\Support\OperatorMessage::event($event->description) }}</p></div>
@empty<p class="empty">Sem histórico registrado.</p>@endforelse
</div>
<form method="post" action="{{ route('clients.note',$company) }}" class="note-form">@csrf<label>Anotação<textarea name="note" rows="2" maxlength="1500" required placeholder="Registrar informação sobre este cliente..."></textarea></label><button class="btn primary small" type="submit">Registrar anotação</button></form>
</section>
</div>
