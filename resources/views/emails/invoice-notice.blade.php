Olá,

A fatura comercial {{ $invoice->number }} da empresa {{ $invoice->company_name }} foi emitida.

Plano: {{ $invoice->plan_name }}
Descrição: {{ $invoice->description }}
Período: {{ $invoice->period_start->format('d/m/Y') }} a {{ $invoice->period_end->format('d/m/Y') }}
Valor: {{ \App\Support\InvoiceMoney::brl($invoice->totalCents()) }}
Vencimento: {{ $invoice->due_date->format('d/m/Y') }}

Consulte os dados da cobrança com o responsável pelo Lumeron.
Esta fatura é comercial e não substitui uma nota fiscal.
Este aviso não representa confirmação de pagamento.

Equipe Lumeron
