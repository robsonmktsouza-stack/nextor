@extends('layouts.main')
@section('title',$plan->exists ? 'Editar plano' : 'Novo plano')
@section('actions')<a href="{{ route('plans.index') }}" class="btn subtle">Voltar aos planos</a>@endsection
@section('content')
<section class="panel control-settings-panel">
<form method="post" action="{{ $plan->exists ? route('plans.update',$plan) : route('plans.store') }}">
@csrf @if($plan->exists)@method('PUT')@endif
<div class="panel-heading"><h2>Dados do plano</h2></div>
<div class="control-settings-inner">
<div class="form-grid">
<label>Código do plano <strong>*</strong>
<input name="code" maxlength="30" required pattern="[a-z][a-z0-9_-]{2,29}" value="{{ old('code',$plan->code) }}" placeholder="ex.: profissional">
</label>
<label>Nome exibido <strong>*</strong><input name="name" maxlength="100" required value="{{ old('name',$plan->name) }}" placeholder="Plano Profissional"></label>
<label class="span-two">Descrição <input name="description" maxlength="255" value="{{ old('description',$plan->description) }}"></label>
<label>Preço (R$)<input name="monthly_price" type="number" min="0" max="9999999999" step="0.01" value="{{ old('monthly_price',$plan->monthly_price) }}" placeholder="Não definido">
</label>
<label>Período<select name="period_months">
@foreach([1,3,6,12] as $months)<option value="{{ $months }}" @selected((int)old('period_months',$plan->period_months ?? 1)===$months)>{{ $months }} {{ $months===1?'mês':'meses' }}</option>@endforeach
</select></label>
</div>
<div class="modules"><strong>Módulos incluídos no plano</strong>
@if($plan->exists && ($customerCount ?? 0) > 0)
<small class="plan-impact-note">As mudanças serão aplicadas a {{ $customerCount }} {{ $customerCount === 1 ? 'cliente' : 'clientes' }} deste plano.</small>
@endif
<div class="module-grid">
@foreach(config('license.modules') as $code=>$label)
<label class="module-switch"><input type="checkbox" role="switch" name="modules[]" value="{{ $code }}" @checked(in_array($code,old('modules',$plan->modules ?? ['pdv','products'])))><span class="switch-track" aria-hidden="true"></span><span>{{ $label }}</span></label>
@endforeach</div></div>
<label class="switch-row settings-check"><input type="hidden" name="active" value="0"><input type="checkbox" role="switch" name="active" value="1" @checked((bool)old('active',$plan->active ?? true))><span class="switch-track" aria-hidden="true"></span><span>Disponível para novas contas</span></label>
<div class="control-form-actions"><a href="{{ route('plans.index') }}" class="btn subtle">Cancelar</a><button type="submit" class="btn primary">Salvar plano</button></div>
</div>
</form>
</section>
@endsection
