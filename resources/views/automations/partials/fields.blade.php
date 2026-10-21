@php
    $editing = $rule->exists;
    $kind = old('kind', $rule->kindCode() ?: 'expiry_soon');
    $days = old('days', $editing ? $rule->days : 7);
@endphp
<div class="form-grid automation-form-grid">
    <label class="span-two">Nome da automação <strong>*</strong>
        <input type="text" name="name" maxlength="100" required
            value="{{ old('name', $rule->name) }}" placeholder="Ex.: Vencimentos desta semana">
    </label>
    <label>Tipo de verificação <strong>*</strong>
        <select name="kind" data-automation-kind @disabled($editing) required>
            @foreach(\App\Models\AutomationRule::KINDS as $code => $label)
                <option value="{{ $code }}" @selected($kind === $code)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label data-automation-days-field>Prazo (dias) <strong>*</strong>
        <input type="number" name="days" min="0" max="365" value="{{ $days }}" required data-automation-days>
    </label>
    <label class="span-two">Descrição <strong>*</strong>
        <input type="text" name="description" maxlength="255" required
            value="{{ old('description', $rule->description) }}" placeholder="Identificação interna da automação">
    </label>
</div>
<label class="switch-row settings-check">
    <input type="hidden" name="enabled" value="0">
    <input type="checkbox" name="enabled" value="1" role="switch" @checked((bool) old('enabled', $editing ? $rule->enabled : false))>
    <span class="switch-track" aria-hidden="true"></span><span>Automação ativa</span>
</label>

<label class="switch-row settings-check" data-automation-email-field>
    <input type="hidden" name="notify_client_email" value="0">
    <input type="checkbox" name="notify_client_email" value="1" role="switch"
        @checked((bool) old('notify_client_email', $editing ? $rule->notify_client_email : false))>
    <span class="switch-track" aria-hidden="true"></span><span>Avisar cliente por e-mail</span>
</label>
