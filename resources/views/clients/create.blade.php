@extends('layouts.main')
@section('title','Nova conta')
@section('actions')<a class="btn subtle" href="{{ route('clients.index') }}">Voltar</a>@endsection
@section('content')
<section class="panel wizard-panel">
<form id="newAccountForm" method="post" action="{{ route('clients.store') }}" data-error-field="{{ $errors->getBag('default')->keys()[0] ?? '' }}">@csrf
<div class="wizard-progress"><span class="bubble current" data-step-dot="0">1</span><span class="wizard-line"></span><span class="bubble" data-step-dot="1">2</span><span class="wizard-line"></span><span class="bubble" data-step-dot="2">3</span></div>
<fieldset data-wizard-step="0"><legend>Dados da empresa</legend>
<div class="form-grid">
<label class="span-two">Razão social <strong>*</strong><input type="text" name="legal_name" maxlength="220" value="{{ old('legal_name') }}" required autofocus>@error('legal_name')<small class="field-error">{{ $message }}</small>@enderror</label>
<label>Nome fantasia <input name="trade_name" maxlength="220" value="{{ old('trade_name') }}"></label>
<label>CNPJ <strong>*</strong><input name="cnpj" inputmode="numeric" maxlength="18" placeholder="00.000.000/0000-00" value="{{ old('cnpj') }}" required>@error('cnpj')<small class="field-error">{{ $message }}</small>@enderror</label>
<label>Nome do contato <input name="contact_name" maxlength="160" value="{{ old('contact_name') }}"></label>
<label>E-mail <strong>*</strong><input name="email" type="email" maxlength="255" value="{{ old('email') }}" required>@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
<label>Telefone <input name="phone" maxlength="30" value="{{ old('phone') }}"></label>
</div></fieldset>
<fieldset data-wizard-step="1" hidden><legend>Acesso da instalação</legend>
<div class="form-grid">
<label>Subdomínio <strong>*</strong><input name="subdomain" maxlength="40" placeholder="empresa-exemplo" value="{{ old('subdomain') }}" pattern="[a-z][a-z0-9-]{1,38}" required><small>Somente letras minúsculas, números e hífens. Ex.: beb-bar-do-dil</small>@error('subdomain')<small class="field-error">{{ $message }}</small>@enderror</label>
<label>Login de referência <input name="admin_login" maxlength="100" pattern="[A-Za-z0-9_.-]{3,100}" value="{{ old('admin_login') }}" placeholder="administrador">@error('admin_login')<small class="field-error">{{ $message }}</small>@enderror</label>
<label class="span-two">Endereço da instalação <input name="installation_url" type="url" placeholder="http://127.0.0.1:8001" value="{{ old('installation_url') }}">@error('installation_url')<small class="field-error">{{ $message }}</small>@enderror</label>
</div></fieldset>
<fieldset data-wizard-step="2" hidden><legend>Plano e liberação</legend>
<div class="form-grid">
<label>Plano <strong>*</strong><select name="plan" required data-plan-selector>@foreach($plans as $plan)<option value="{{ $plan->code }}" data-modules="{{ implode(',', $plan->modules ?? []) }}" @selected(old('plan',$plans->first()?->code)===$plan->code)>{{ $plan->name }}</option>@endforeach</select>@error('plan')<small class="field-error">{{ $message }}</small>@enderror</label>
<label>Vencimento <input name="expires_at" type="date" value="{{ old('expires_at') }}">@error('expires_at')<small class="field-error">{{ $message }}</small>@enderror</label>
</div>
<div class="modules"><strong>Módulos liberados</strong><input type="hidden" name="modules_present" value="1"><div class="module-grid">@foreach(config('license.modules') as $code=>$label)<label class="module-switch"><input type="checkbox" role="switch" name="modules[]" value="{{ $code }}" @checked(in_array($code,old('modules',$plans->firstWhere('code',old('plan',$plans->first()?->code))?->modules ?? [])))><span class="switch-track" aria-hidden="true"></span><span>{{ $label }}</span></label>@endforeach</div></div>
</fieldset>
<div class="wizard-footer"><button class="btn subtle" type="button" data-wizard-back hidden>Voltar</button><span class="nav-spacer"></span><button class="btn primary" type="button" data-wizard-next>Próximo <svg><use href="#i-arrow"/></svg></button><button class="btn primary" type="submit" data-wizard-submit hidden><svg><use href="#i-check"/></svg>Criar conta</button></div>
</form>
</section>
@endsection
