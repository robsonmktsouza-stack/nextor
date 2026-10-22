<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\InvoiceService;
use App\Support\InvoiceMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()->with('company');
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('number', 'like', '%'.$search.'%')
                ->orWhere('company_name', 'like', '%'.$search.'%')
                ->orWhere('company_cnpj', 'like', '%'.preg_replace('/\D/', '', $search).'%'));
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        $status = (string) $request->query('status', '');
        $paid = '(SELECT COALESCE(SUM(ip.amount),0) FROM invoice_payments ip'
            .' WHERE ip.invoice_id = invoices.id AND ip.reversed_at IS NULL)';
        if ($status === 'draft' || $status === 'cancelled') {
            $query->where('status', $status);
        } elseif (in_array($status, ['open', 'overdue', 'partial', 'paid'], true)) {
            $query->where('status', 'issued');
            match ($status) {
                'paid' => $query->whereRaw($paid.' >= invoices.amount'),
                'overdue' => $query->whereDate('due_date', '<', today())
                    ->whereRaw($paid.' < invoices.amount'),
                'partial' => $query->whereDate('due_date', '>=', today())
                    ->whereRaw($paid.' > 0')->whereRaw($paid.' < invoices.amount'),
                default => $query->whereDate('due_date', '>=', today())
                    ->whereRaw($paid.' = 0'),
            };
        }
        $perPage = (int) $request->query('per_page', 20);
        $perPage = in_array($perPage, [20,50,100], true) ? $perPage : 20;
        return view('invoices.index', [
            'invoices' => $query->orderByDesc('id')->paginate($perPage)->withQueryString(),
            'summary' => [
                'drafts' => Invoice::query()->where('status', 'draft')->count(),
                'overdue' => Invoice::query()->where('status', 'issued')
                    ->whereDate('due_date', '<', today())->whereRaw($paid.' < invoices.amount')->count(),
                'outstanding' => Invoice::query()->where('status', 'issued')
                    ->whereRaw($paid.' < invoices.amount')->count(),
                'paid' => Invoice::query()->where('status', 'issued')
                    ->whereRaw($paid.' >= invoices.amount')->count(),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $companies = Company::query()->with('planDefinition')->orderBy('legal_name')->get();
        $selected = $companies->firstWhere('id', (int) $request->query('company_id'))
            ?? $companies->first();
        $start = $selected?->expires_at && $selected->expires_at->gte(today())
            ? $selected->expires_at->copy()->addDay() : today();
        $months = (int) ($selected?->planDefinition?->period_months ?? 1);
        return view('invoices.form', [
            'invoice' => new Invoice(),
            'companies' => $companies,
            'defaultStart' => $start->toDateString(),
            'defaultEnd' => $start->copy()->addMonthsNoOverflow($months)->subDay()->toDateString(),
            'defaultDue' => ($selected?->expires_at && $selected->expires_at->gte(today())
                ? $selected->expires_at : today()->addDays(7))->toDateString(),
            'selected' => $selected,
        ]);
    }

    public function store(Request $request, InvoiceService $service)
    {
        $data = $this->validated($request);
        $company = Company::query()->findOrFail($data['company_id']);
        $invoice = $service->create($company, $data);
        return redirect()->route('invoices.show', $invoice)->with('success', 'Fatura criada.');
    }

    public function show(Invoice $invoice)
    {
        return view('invoices.show', [
            'invoice' => $invoice->load(['company', 'payments', 'emailDelivery']),
        ]);
    }

    public function edit(Invoice $invoice)
    {
        abort_unless($invoice->status === 'draft', 409, 'Somente rascunhos podem ser editados.');
        return view('invoices.form', [
            'invoice' => $invoice,
            'companies' => Company::query()->with('planDefinition')->whereKey($invoice->company_id)->get(),
            'selected' => $invoice->company,
            'defaultStart' => $invoice->period_start->toDateString(),
            'defaultEnd' => $invoice->period_end->toDateString(),
            'defaultDue' => $invoice->due_date->toDateString(),
        ]);
    }

    public function update(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $data = $this->validated($request, $invoice);
        $service->edit($invoice, $data);
        return redirect()->route('invoices.show', $invoice)->with('success', 'Fatura atualizada.');
    }

    public function issue(Invoice $invoice, InvoiceService $service)
    {
        $service->issue($invoice);
        return back()->with('success', 'Fatura emitida.');
    }

    public function pay(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $data = $request->validate([
            'amount' => ['required','regex:/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/'],
            'paid_on' => ['required','date_format:Y-m-d','before_or_equal:today'],
            'method' => ['required', Rule::in(['pix','boleto','transferencia','cartao','dinheiro','outro'])],
            'reference' => ['nullable','string','max:120'],
            'notes' => ['nullable','string','max:255'],
        ]);
        $service->recordPayment($invoice, $data);
        return back()->with('success', 'Pagamento registrado.');
    }

    public function reverse(Request $request, Invoice $invoice, InvoicePayment $payment, InvoiceService $service)
    {
        $data = $request->validate(['reason' => ['required','string','min:5','max:255']]);
        $service->reversePayment($invoice, $payment, $data['reason']);
        return back()->with('success', 'Pagamento estornado.');
    }

    public function applyLicense(Invoice $invoice, InvoiceService $service)
    {
        $service->applyPaidPeriod($invoice);
        return back()->with('success', 'Validade da licença renovada.');
    }

    public function undoLicense(Invoice $invoice, InvoiceService $service)
    {
        $service->undoPaidPeriod($invoice);
        return back()->with('success', 'Renovação da licença desfeita.');
    }

    public function cancel(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $data = $request->validate(['reason' => ['required','string','min:5','max:255']]);
        $service->cancel($invoice, $data['reason']);
        return redirect()->route('invoices.show', $invoice)->with('success', 'Fatura cancelada.');
    }

    private function validated(Request $request, ?Invoice $invoice = null): array
    {
        $rules = [
            'description' => [$invoice ? 'required' : 'nullable','string','max:255'],
            'period_start' => ['required','date_format:Y-m-d'],
            'period_end' => ['required','date_format:Y-m-d','after_or_equal:period_start'],
            'issue_date' => ['required','date_format:Y-m-d'],
            'due_date' => ['required','date_format:Y-m-d','after_or_equal:issue_date'],
            'amount' => [$invoice ? 'required' : 'nullable', 'regex:/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/'],
        ];
        if (!$invoice) {
            $rules['company_id'] = ['required','integer', Rule::exists('companies','id')];
        } else {
            $rules['company_id'] = ['prohibited'];
        }
        return $request->validate($rules);
    }
}
