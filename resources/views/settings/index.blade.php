@extends('layouts.main')
@section('title', 'Configurações')
@section('subtitle', 'E-mail, faturamento e notificações')
@section('content')
@php
    $s = $settings;
    $mailEnabled = old('email_enabled', $s?->email_enabled ?? false);
    $billEnabled = old('recurring_invoices_enabled', $s?->recurring_invoices_enabled ?? true);
    $invoiceMailEnabled = old('invoice_email_enabled', $s?->invoice_email_enabled ?? false);
    $licenseMailEnabled = old('license_email_enabled', $s?->license_email_enabled ?? false);
@endphp
<div class="control-settings-overview" aria-label="Resumo das configurações">
    <div class="control-settings-indicator"><span class="control-settings-indicator-title">E-mail</span>
        <strong class="{{ $emailReady ? 'is-on' : 'is-off' }}">{{ $emailReady ? 'Ativo' : ($smtpReady ? 'Pronto para ativar' : 'Não configurado') }}</strong></div>
    <div class="control-settings-indicator"><span class="control-settings-indicator-title">Faturas recorrentes</span>
        <strong class="{{ ($s?->recurring_invoices_enabled ?? true) ? 'is-on' : 'is-off' }}">{{ ($s?->recurring_invoices_enabled ?? true) ? 'Ativas' : 'Desativadas' }}</strong></div>
    <div class="control-settings-indicator"><span class="control-settings-indicator-title">Envio de faturas</span>
        <strong class="{{ ($s?->invoice_email_enabled ?? false) && $emailReady ? 'is-on' : 'is-off' }}">{{ ($s?->invoice_email_enabled ?? false) && $emailReady ? 'Ativo' : 'Desativado' }}</strong></div>
    <div class="control-settings-indicator"><span class="control-settings-indicator-title">Avisos de licença</span>
        <strong class="{{ ($s?->license_email_enabled ?? false) && $emailReady ? 'is-on' : 'is-off' }}">{{ ($s?->license_email_enabled ?? false) && $emailReady ? 'Ativos' : 'Desativados' }}</strong></div>
</div>

<div class="control-settings-layout">
    <section id="email" class="panel control-settings-panel">
        <div class="panel-heading"><h2>@include('partials.icon',['name'=>'mail','size'=>18]) Servidor de e-mail</h2></div>
        <form method="post" action="{{ route('settings.mail') }}" autocomplete="off">
            @csrf @method('PUT')
            <div class="control-settings-body">
                <div class="form-grid">
                    <label class="span-two">Servidor SMTP
                        <input name="smtp_host" value="{{ old('smtp_host', $s?->smtp_host ?? '') }}" maxlength="253" placeholder="smtp.seudominio.com.br" autocomplete="off"></label>
                    <label>Porta
                        <input name="smtp_port" type="number" min="1" max="65535" value="{{ old('smtp_port', $s?->smtp_port ?? 587) }}" required></label>
                    <label>Proteção da conexão
                        <select name="smtp_security">
                            <option value="tls" @selected(old('smtp_security', $s?->smtp_security ?? 'tls') === 'tls')>STARTTLS</option>
                            <option value="ssl" @selected(old('smtp_security', $s?->smtp_security ?? 'tls') === 'ssl')>SSL/TLS</option>
                        </select>
                    </label>
                    <label class="span-two">Usuário de e-mail
                        <input name="smtp_username" value="{{ old('smtp_username', $s?->smtp_username ?? '') }}" maxlength="255" autocomplete="off"></label>
                    <label class="span-two">Senha do e-mail
                        <input type="password" name="password" value="" maxlength="2048" placeholder="{{ filled($s?->smtp_password) ? 'Senha guardada • deixar em branco para manter' : 'Informe a senha ou senha de aplicativo' }}" autocomplete="new-password"></label>
                    <label>E-mail do remetente
                        <input name="smtp_from_address" type="email" maxlength="255" value="{{ old('smtp_from_address', $s?->smtp_from_address ?? '') }}" placeholder="financeiro@exemplo.com.br"></label>
                    <label>Nome do remetente
                        <input name="smtp_from_name" maxlength="100" value="{{ old('smtp_from_name', $s?->smtp_from_name ?? 'Lumeron') }}" required></label>
                </div>
                <label class="switch-row control-settings-switch">
                    <input type="hidden" name="email_enabled" value="0">
                    <input type="checkbox" role="switch" name="email_enabled" value="1" @checked((bool)$mailEnabled)>
                    <span class="switch-track"></span><span>Ativar envio de e-mails</span>
                </label>
            </div>
            <div class="control-settings-actions">
                <button type="submit" class="btn primary">@include('partials.icon',['name'=>'check','size'=>16]) Salvar e-mail</button>
            </div>
        </form>
        <form method="post" action="{{ route('settings.mail-test') }}" class="control-settings-test">
            @csrf
            <span>Enviar teste para <strong>{{ auth()->user()->email }}</strong></span>
            <button class="btn subtle" type="submit" @disabled(!$smtpReady)>@include('partials.icon',['name'=>'send','size'=>16]) Enviar teste</button>
        </form>
    </section>

    <div class="control-settings-side">
        <section id="faturamento" class="panel control-settings-panel">
            <div class="panel-heading"><h2>@include('partials.icon',['name'=>'receipt','size'=>18]) Faturamento</h2></div>
            <form method="post" action="{{ route('settings.billing') }}">
                @csrf @method('PUT')
                <div class="control-settings-body">
                    <label class="switch-row control-settings-switch">
                        <input type="hidden" name="recurring_invoices_enabled" value="0">
                        <input type="checkbox" role="switch" name="recurring_invoices_enabled" value="1" @checked((bool)$billEnabled)>
                        <span class="switch-track"></span><span>Preparar faturas recorrentes</span>
                    </label>
                    <label class="switch-row control-settings-switch">
                        <input type="hidden" name="invoice_email_enabled" value="0">
                        <input type="checkbox" role="switch" name="invoice_email_enabled" value="1" @checked((bool)$invoiceMailEnabled)>
                        <span class="switch-track"></span><span>Enviar faturas emitidas por e-mail</span>
                    </label>
                    <label class="switch-row control-settings-switch">
                        <input type="hidden" name="license_email_enabled" value="0">
                        <input type="checkbox" role="switch" name="license_email_enabled" value="1" @checked((bool)$licenseMailEnabled)>
                        <span class="switch-track"></span><span>Avisar vencimento de licenças por e-mail</span>
                    </label>
                    <label class="control-settings-interval">Preparar com antecedência
                        <span class="control-settings-number"><input name="invoice_days_ahead" type="number" min="0" max="30" value="{{ old('invoice_days_ahead', $s?->invoice_days_ahead ?? 7) }}" required> dias</span>
                    </label>
                    <p class="control-settings-small-note">A preparação gera rascunhos. Para avisos de licença, escolha os prazos em <a href="{{ route('automations.index') }}">Automações</a>.</p>
                </div>
                <div class="control-settings-actions">
                    <button class="btn primary" type="submit">@include('partials.icon',['name'=>'check','size'=>16]) Salvar faturamento</button>
                </div>
            </form>
        </section>
        <section id="agendamentos" class="panel control-settings-panel">
            <div class="panel-heading"><h2>@include('partials.icon',['name'=>'clock','size'=>18]) Agendamentos</h2></div>
            <form method="post" action="{{ route('settings.schedules') }}">
                @csrf @method('PUT')
                <div class="control-settings-body">
                    <div class="form-grid">
                        <label>Verificar vencimentos
                            <input name="automations_at" type="time" value="{{ old('automations_at', $s?->automations_at ?? '08:00') }}" required></label>
                        <label>Preparar faturas
                            <input name="invoices_at" type="time" value="{{ old('invoices_at', $s?->invoices_at ?? '08:05') }}" required></label>
                        <label>Início dos envios
                            <input name="emails_start_at" type="time" value="{{ old('emails_start_at', $s?->emails_start_at ?? '08:10') }}" required></label>
                        <label>Fim dos envios
                            <input name="emails_end_at" type="time" value="{{ old('emails_end_at', $s?->emails_end_at ?? '18:00') }}" required></label>
                    </div>
                    <p class="control-settings-small-note">Os avisos são processados a cada 10 minutos durante o período definido.</p>
                </div>
                <div class="control-settings-actions">
                    <button class="btn primary" type="submit">@include('partials.icon',['name'=>'check','size'=>16]) Salvar horários</button>
                </div>
            </form>
        </section>
    </div>
</div>
@endsection
