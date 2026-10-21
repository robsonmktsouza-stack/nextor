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
<label>CNPJ <input value="{{ $company->cnpj }}" disabled><small>Vinculado à instalação; não editável.</small></label>
<label>Responsável <input name="contact_name" value="{{ $company->contact_name }}" maxlength="160"></label>
<label>E-mail <input name="email" type="email" value="{{ $company->email }}" required></label>
<label>Telefone <input name="phone" value="{{ $company->phone }}" maxlength="30"></label>
<label>Login inicial de referência <input name="admin_login" value="{{ $company->admin_login }}" maxlength="100"></label>
<label class="span-two">URL da instalação <input name="installation_url" type="url" value="{{ $company->installation_url }}" placeholder="https://..."></label>
</div><button type="submit" class="btn primary small">Salvar dados</button>
</form>
<div class="metadata"><strong>Subdomínio</strong><span>{{ $company->subdomain }}</span><strong>Integração</strong><span><span class="status {{ $company->last_seen_at ? 'active' : 'expired' }}">{{ $company->last_seen_at ? 'Conectada anteriormente' : 'Aguardando instalação' }}</span></span><strong>Última comunicação</strong><span>{{ $company->last_seen_at?->format('d/m/Y H:i')??'Ainda não conectada' }}</span><strong>Versão informada</strong><span>{{ $company->last_version?:'—' }}</span></div>
</section>
<section class="detail-box"><h3>Licença e acesso</h3>
<div class="license-summary"><div><span>Plano atual</span><strong>{{ config('license.plans')[$company->plan]??$company->plan }}</strong></div><div><span>Vencimento</span><strong>{{ $company->expires_at?->format('d/m/Y')??'Sem prazo' }}</strong></div></div>
<form method="post" action="{{ route('clients.status',$company) }}" class="status-controls">@csrf
@if($company->license_status==='suspended')
<input type="hidden" name="action" value="activate"><button class="btn success" type="submit" data-confirm="Reativar a licença deste cliente?"><svg><use href="#i-check"/></svg>Reativar acesso</button>
@else
<input type="hidden" name="action" value="suspend"><input type="text" name="reason" placeholder="Motivo (opcional)" maxlength="250"><button class="btn danger" type="submit" data-confirm="Suspender o acesso comercial deste cliente?"><svg><use href="#i-lock"/></svg>Suspender acesso</button>
@endif
</form>
<form method="post" action="{{ route('clients.renew',$company) }}" class="renew-controls">@csrf
<label>Renovar por<select name="months"><option value="1">1 mês</option><option value="3">3 meses</option><option value="6">6 meses</option><option value="12">12 meses</option></select></label><button class="btn primary" type="submit"><svg><use href="#i-calendar"/></svg>Renovar</button>
</form>
<form method="post" action="{{ route('clients.modules',$company) }}">@csrf
<div class="form-grid"><label>Plano<select name="plan">@foreach(config('license.plans') as $value=>$label)<option value="{{ $value }}" @selected($company->plan===$value)>{{ $label }}</option>@endforeach</select></label><label>Validade<input type="date" name="expires_at" value="{{ $company->expires_at?->format('Y-m-d') }}"></label></div>
<div class="modules"><strong>Módulos autorizados</strong><div class="module-grid">@foreach(config('license.modules') as $code=>$label)<label class="check"><input type="checkbox" name="modules[]" value="{{ $code }}" @checked(in_array($code,$company->modules??[]))>{{ $label }}</label>@endforeach</div></div>
<button type="submit" class="btn primary small">Salvar licença</button></form>
<div class="info-line">A liberação, suspensão e renovação são feitas somente aqui. Quando a instalação estiver conectada ao serviço central, as alterações serão recebidas automaticamente, sem ação do cliente.</div>
</section>
<section class="detail-box"><h3>Anotações e eventos</h3>
<div class="events-stream">
@forelse($company->events as $event)
<div class="event"><div><strong>{{ $event->user?->name??'Sistema' }}</strong><small>{{ $event->created_at?->format('d/m/Y H:i') }}</small></div><p>{{ $event->description }}</p></div>
@empty<p class="empty">Sem histórico registrado.</p>@endforelse
</div>
<form method="post" action="{{ route('clients.note',$company) }}" class="note-form">@csrf<label>Anotação<textarea name="note" rows="2" maxlength="1500" required placeholder="Registrar informação sobre este cliente..."></textarea></label><button class="btn primary small" type="submit">Registrar anotação</button></form>
</section>
</div>
