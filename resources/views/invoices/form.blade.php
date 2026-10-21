@extends('layouts.main')
@section('title', $invoice->exists ? 'Editar fatura' : 'Nova fatura')
@section('actions')<a class="btn subtle" href="{{ $invoice->exists ? route('invoices.show',$invoice) : route('invoices.index') }}">Voltar</a>@endsection
@section('content')
<section class="panel control-settings-panel">
    <div class="panel-heading"><h2>Dados da fatura</h2></div>
    <form method="post" action="{{ $invoice->exists ? route('invoices.update',$invoice) : route('invoices.store') }}" data-invoice-editor @if(old('company_id')) data-invoice-preserve="1" @endif>
        @csrf
        @if($invoice->exists) @method('PUT') @endif
        <div class="control-settings-inner">
            <div class="form-grid">
                <label class="span-two">Cliente <strong>*</strong>
                    @if($invoice->exists)
                        <input type="text" value="{{ $invoice->company_name }}" readonly>
                    @else
                        <select name="company_id" required data-invoice-company>
                            @foreach($companies as $company)
                                @php
                                    $plan = $company->planDefinition;
                                    $period = (int) ($plan?->period_months ?? 1);
                                    $cents = $plan?->monthly_price === null ? null : \App\Support\InvoiceMoney::cents((string) $plan->monthly_price) * $period;
                                    $start = $company->expires_at && $company->expires_at->gte(today()) ? $company->expires_at->copy()->addDay() : today();
                                @endphp
                                <option value="{{ $company->id }}"
                                    data-plan="{{ $plan?->name ?? 'Sem plano' }}"
                                    data-period="{{ $period }}"
                                    data-price="{{ $cents !== null && $cents <= 999999999999 ? \App\Support\InvoiceMoney::decimal($cents) : '' }}"
                                    data-start="{{ $start->toDateString() }}"
                                    data-due="{{ ($company->expires_at && $company->expires_at->gte(today()) ? $company->expires_at : today()->addDays(7))->toDateString() }}"
                                    @selected((int)old('company_id',$selected?->id)===$company->id)>
                                    {{ $company->legal_name }} — {{ $company->cnpj }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </label>
                <label>Plano
                    <input type="text" readonly data-invoice-plan
                        value="{{ $invoice->exists ? $invoice->plan_name : ($selected?->planDefinition?->name ?? 'Não definido') }}">
                </label>
                <label>Descrição <input type="text" name="description" maxlength="255"
                    value="{{ old('description',$invoice->description) }}"
                    placeholder="Assinatura mensal" @if($invoice->exists) required @endif></label>
                <label>Início do período <strong>*</strong>
                    <input type="date" name="period_start" data-invoice-period-start required
                        value="{{ old('period_start',$defaultStart) }}">
                </label>
                <label>Fim do período <strong>*</strong>
                    <input type="date" name="period_end" data-invoice-period-end required
                        value="{{ old('period_end',$defaultEnd) }}">
                </label>
                <label>Emissão <strong>*</strong>
                    <input type="date" name="issue_date" required
                        value="{{ old('issue_date',$invoice->exists ? $invoice->issue_date->toDateString() : today()->toDateString()) }}">
                </label>
                <label>Vencimento <strong>*</strong>
                    <input type="date" name="due_date" data-invoice-due required value="{{ old('due_date',$defaultDue) }}">
                </label>
                <label>Valor (R$) <strong>*</strong>
                    <input type="number" name="amount" data-invoice-amount min="0.01" step="0.01"
                        value="{{ old('amount',$invoice->exists ? $invoice->amount : '') }}"
                        placeholder="Valor do período" @if($invoice->exists) required @endif>
                </label>
            </div>
            <div class="control-form-actions">
                <a class="btn subtle" href="{{ $invoice->exists ? route('invoices.show',$invoice) : route('invoices.index') }}">Cancelar</a>
                <button type="submit" class="btn primary">@include('partials.icon',['name'=>'check','size'=>16])Salvar fatura</button>
            </div>
        </div>
    </form>
</section>
@endsection
