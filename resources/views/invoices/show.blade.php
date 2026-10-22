@extends('layouts.main')
@section('title', 'Fatura '.$invoice->number)
@section('actions')
    <a href="{{ route('invoices.index') }}" class="btn subtle">Voltar</a>
    @if($invoice->status==='draft')
        <a href="{{ route('invoices.edit',$invoice) }}" class="btn subtle">@include('partials.icon',['name'=>'edit','size'=>16])Editar</a>
        <form method="post" action="{{ route('invoices.issue',$invoice) }}">@csrf
            <button class="btn primary" type="submit" data-confirm="Emitir esta fatura? Depois de emitir, seus dados não poderão ser alterados.">
                @include('partials.icon',['name'=>'check','size'=>16])Emitir fatura
            </button>
        </form>
    @endif
    @if($invoice->status==='issued' && $invoice->balanceCents()===0 && !$invoice->license_applied_at)
        <form method="post" action="{{ route('invoices.apply-license',$invoice) }}">@csrf
            <button class="btn primary" type="submit" data-confirm="Aplicar o período quitado à validade do cliente? Isso será refletido no ERP conectado.">
                @include('partials.icon',['name'=>'calendar','size'=>16])Renovar licença
            </button>
        </form>
    @elseif($invoice->licenseApplicationActive())
        <form method="post" action="{{ route('invoices.undo-license',$invoice) }}">@csrf
            <button class="btn subtle" type="submit" data-confirm="Desfazer a renovação de validade aplicada por esta fatura?">
                Desfazer renovação
            </button>
        </form>
    @endif
    <button type="button" class="btn subtle" onclick="window.print()">
        @include('partials.icon',['name'=>'print','size'=>16])Imprimir
    </button>
@endsection
@section('content')
@php
    $effective = $invoice->effectiveStatus();
    $cents = $invoice->totalCents();
    $paid = $invoice->paidCents();
    $balance = $invoice->balanceCents();
@endphp
<section class="panel invoice-document" aria-label="Fatura comercial">
    <div class="panel-heading invoice-heading">
        <div><h2>{{ $invoice->number }}</h2><small>Fatura comercial · Não é documento fiscal</small></div>
        <span class="status {{ $effective==='paid' ? 'active' : ($effective==='cancelled' ? 'suspended' : 'expired') }}">{{ $invoice->statusLabel() }}</span>
    </div>
    <div class="invoice-document-body">
        <div class="invoice-document-grid">
            <div>
                <span class="invoice-label">Cliente</span>
                <strong>{{ $invoice->company_name }}</strong>
                <div>{{ $invoice->company_cnpj }}</div>
                <div><a href="{{ route('clients.show',$invoice->company) }}">Ver cadastro</a></div>
            </div>
            <div>
                <span class="invoice-label">Assinatura</span>
                <strong>{{ $invoice->plan_name }}</strong>
                <div>{{ $invoice->period_start->format('d/m/Y') }} a {{ $invoice->period_end->format('d/m/Y') }}</div>
            </div>
            <div>
                <span class="invoice-label">Datas</span>
                <div>Emissão: {{ $invoice->issue_date->format('d/m/Y') }}</div>
                <div>Vencimento: {{ $invoice->due_date->format('d/m/Y') }}</div>
            </div>
        </div>
        <div class="invoice-line-item"><span>{{ $invoice->description }}</span><strong>{{ \App\Support\InvoiceMoney::brl($cents) }}</strong></div>
        <div class="invoice-totals">
            <div><span>Total</span><strong>{{ \App\Support\InvoiceMoney::brl($cents) }}</strong></div>
            <div><span>Recebido</span><strong>{{ \App\Support\InvoiceMoney::brl($paid) }}</strong></div>
            <div><span>Saldo</span><strong>{{ \App\Support\InvoiceMoney::brl($balance) }}</strong></div>
        </div>
        @if($invoice->emailDelivery)
            <div class="invoice-license-note">E-mail da fatura:
                {{ match($invoice->emailDelivery->status) {
                    'sent' => 'Enviado', 'pending' => 'Aguardando envio',
                    'processing' => 'Enviando', 'retry' => 'Nova tentativa',
                    'failed' => 'Não enviado', default => 'Cancelado'
                } }}
            </div>
        @endif
        @if($invoice->licenseApplicationActive())
            <div class="invoice-license-note">Validade aplicada à licença em {{ $invoice->license_applied_at->format('d/m/Y H:i') }}.</div>
        @elseif($invoice->license_application_reversed_at)
            <div class="invoice-license-note">Aplicação à licença desfeita em {{ $invoice->license_application_reversed_at->format('d/m/Y H:i') }}.</div>
        @endif
        @if($invoice->status==='cancelled')
            <div class="invoice-cancellation">Cancelada em {{ $invoice->cancelled_at?->format('d/m/Y H:i') }}. {{ $invoice->cancellation_reason }}</div>
        @endif
    </div>
</section>
@if($invoice->status==='issued' && $balance>0)
<section class="panel invoice-control-panel">
    <div class="panel-heading"><h2>Registrar pagamento</h2></div>
    <form method="post" action="{{ route('invoices.pay',$invoice) }}" class="invoice-pay-form">
        @csrf
        <div class="form-grid">
            <label>Valor recebido (R$)<input name="amount" type="number" step="0.01" min="0.01"
                    max="{{ \App\Support\InvoiceMoney::decimal($balance) }}" value="{{ old('amount', \App\Support\InvoiceMoney::decimal($balance)) }}" required></label>
            <label>Data do pagamento<input name="paid_on" type="date" max="{{ today()->toDateString() }}"
                    value="{{ old('paid_on',today()->toDateString()) }}" required></label>
            <label>Forma de pagamento<select name="method" required>
                @foreach(['pix'=>'Pix','boleto'=>'Boleto','transferencia'=>'Transferência','cartao'=>'Cartão','dinheiro'=>'Dinheiro','outro'=>'Outro'] as $code=>$label)
                    <option value="{{ $code }}" @selected(old('method')===$code)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label>Referência<input name="reference" maxlength="120" value="{{ old('reference') }}" placeholder="Identificação do pagamento"></label>
            <label class="span-two">Observações<input name="notes" maxlength="255" value="{{ old('notes') }}"></label>
        </div>
        <button type="submit" class="btn primary">@include('partials.icon',['name'=>'check','size'=>16])Registrar pagamento</button>
    </form>
</section>
@endif
@if($invoice->payments->isNotEmpty())
<section class="panel license-grid invoice-payment-history">
    <div class="panel-heading"><h2>Pagamentos registrados</h2></div>
    <div class="table-scroll">
        <table class="license-data-table">
            <thead><tr><th>Data</th><th>Forma</th><th>Referência</th><th>Valor</th><th>Situação</th><th class="license-actions-heading">Ações</th></tr></thead>
            <tbody>
            @foreach($invoice->payments as $payment)
                <tr>
                    <td>{{ $payment->paid_on->format('d/m/Y') }}</td>
                    <td>{{ ['pix'=>'Pix','boleto'=>'Boleto','transferencia'=>'Transferência','cartao'=>'Cartão','dinheiro'=>'Dinheiro','outro'=>'Outro'][$payment->method] ?? 'Outro' }}</td>
                    <td>{{ $payment->reference ?? '—' }}</td>
                    <td>{{ \App\Support\InvoiceMoney::brl(\App\Support\InvoiceMoney::cents((string)$payment->amount)) }}</td>
                    <td><span class="status {{ $payment->reversed_at ? 'suspended' : 'active' }}">{{ $payment->reversed_at ? 'Estornado' : 'Confirmado' }}</span></td>
                    <td>
                        @if(!$payment->reversed_at && $invoice->status==='issued')
                            <form method="post" action="{{ route('invoices.payments.reverse',[$invoice,$payment]) }}" class="invoice-reversal">
                                @csrf
                                <input type="text" name="reason" required minlength="5" maxlength="255" placeholder="Motivo do estorno" aria-label="Motivo do estorno">
                                <button type="submit" class="btn subtle small" data-confirm="Estornar este pagamento?">Estornar</button>
                            </form>
                        @else
                            {{ $payment->reversal_reason ?? '—' }}
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif
@if($invoice->status!=='cancelled' && $paid===0)
<section class="invoice-danger-zone">
    <form method="post" action="{{ route('invoices.cancel',$invoice) }}" class="invoice-cancel-form">
        @csrf
        <label>Motivo do cancelamento <input type="text" name="reason" required minlength="5" maxlength="255" placeholder="Informe o motivo"></label>
        <button type="submit" class="btn danger" data-confirm="Cancelar definitivamente esta fatura?">
            @include('partials.icon',['name'=>'x','size'=>16])Cancelar fatura
        </button>
    </form>
</section>
@endif
@endsection
