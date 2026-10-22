<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use App\Models\ManagedProvisioningJob;
use App\Services\InvoiceCycleService;
use App\Support\Cnpj;
use App\Support\Subdomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CompanyController extends Controller
{
    /**
     * Endereço de uma instalação já existente. HTTPS é obrigatório para
     * endereços remotos; HTTP só é aceito com localhost/loopback em desenvolvimento.
     */
    private function installationUrlRules(): array
    {
        return [
            'nullable', 'url', 'max:500',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (!is_string($value) || trim($value) === '') {
                    return;
                }

                $parts = parse_url($value);
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                $host = strtolower((string) ($parts['host'] ?? ''));

                if ($scheme === 'https') {
                    return;
                }

                if ($scheme === 'http' && app()->environment('local', 'testing')
                    && (in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                        || (app()->environment('local')
                            && preg_match('/^lumeron-[a-z0-9][a-z0-9-]{0,37}[a-z0-9]\.test$/', $host)))) {
                    return;
                }

                $fail('Informe um endereço seguro (HTTPS).');
            },
        ];
    }

    public function index(Request $request)
    {
        return $this->listing($request, false);
    }

    public function listing(Request $request, bool $licenses)
    {
        $query = Company::query();
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        if ($search !== '') {
            $digits = Cnpj::digits($search);
            $query->where(function ($q) use ($search, $digits): void {
                $q->where('legal_name', 'like', "%{$search}%")
                    ->orWhere('trade_name', 'like', "%{$search}%")
                    ->orWhere('subdomain', 'like', "%{$search}%");
                if ($digits !== '') {
                    $q->orWhere('cnpj', 'like', "%{$digits}%");
                }
            });
        }
        if ($status === 'suspended') {
            $query->where('license_status', 'suspended');
        } elseif ($status === 'expired') {
            $query->where('license_status', 'active')->whereDate('expires_at', '<', now()->toDateString());
        } elseif ($status === 'active') {
            $query->where('license_status', 'active')->where(
                fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString())
            );
        }
        if ($request->filled('plan') && Plan::query()->where('code', (string) $request->query('plan'))->exists()) {
            $query->where('plan', $request->query('plan'));
        }
        return view('clients.index', [
            'companies' => $query->orderByDesc('id')->paginate(in_array((int)$request->query('per_page',20),[20,50,100],true) ? (int)$request->query('per_page',20) : 20)->withQueryString(),
            'licenses' => $licenses,
            'plans' => Plan::query()->orderBy('id')->get(),
        ]);
    }

    public function create()
    {
        return view('clients.create', ['plans' => Plan::enabled()->get()]);
    }

    public function store(Request $request, InvoiceCycleService $cycles)
    {
        $request->merge([
            'cnpj' => Cnpj::digits($request->input('cnpj')),
            'subdomain' => Subdomain::normalize((string) $request->input('subdomain')),
        ]);
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'min:3', 'max:220'],
            'trade_name' => ['nullable', 'string', 'max:220'],
            'cnpj' => ['required', 'digits:14', Rule::unique('companies', 'cnpj'),
                fn ($field, $value, $fail) => Cnpj::valid($value) ?: $fail('CNPJ inválido.')],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'subdomain' => ['required', 'string', 'max:39',
                fn ($field, $value, $fail) => Subdomain::valid((string) $value)
                    ?: $fail('Identificador inválido ou reservado.'),
                Rule::unique('companies', 'subdomain')],
            'installation_url' => $this->installationUrlRules(),
            'admin_login' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_.-]{3,100}$/'],
            'plan' => ['required', Rule::exists('plans', 'code')->where('active', true)],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'modules' => ['prohibited'],
            'modules_present' => ['prohibited'],
        ]);
        // A new licence must not accidentally become unlimited just because
        // the existing optional date field was left blank.
        $data['expires_at'] = $data['expires_at'] ?? today()->toDateString();
        $secret = bin2hex(random_bytes(48));
        [$company, $invoice] = DB::transaction(function () use ($data, $secret, $cycles): array {
            $company = Company::create([
                ...$data,
                'instance_id' => (string) Str::uuid(),
                'license_secret' => $secret,
                'license_status' => 'active',
                'modules' => null,
            ]);
            $company->record('created', 'Conta cadastrada e licença criada.');
            $invoice = $cycles->prepareForNewCompany($company);
            // Registration only enqueues work. Root-only installation runs
            // asynchronously on the trusted VPS, never via HTTP.
            ManagedProvisioningJob::query()->create([
                'company_id' => $company->id,
                'status' => 'queued',
            ]);
            return [$company, $invoice];
        });
        return redirect()->route('clients.show', $company)->with('success',
            $invoice ? 'Conta cadastrada. Fatura preparada em rascunho. Acesso ERP aguardando preparação.'
                : 'Conta cadastrada. Configure o preço do plano para preparar a fatura. Acesso ERP aguardando preparação.');
    }

    public function show(Company $company)
    {
        return view('clients.show', [
            'company' => $company->load(['events' => fn ($q) => $q->latest('id')->limit(30), 'events.user']),
            'plans' => Plan::query()->where('active', true)->orWhere('code', $company->plan)->orderBy('id')->get(),
        ]);
    }

    public function details(Company $company)
    {
        return view('clients.partials.details', [
            'company' => $company->load(['events' => fn ($q) => $q->latest('id')->limit(30), 'events.user']),
            'plans' => Plan::query()->where('active', true)->orWhere('code', $company->plan)->orderBy('id')->get(),
        ]);
    }

    /**
     * Development-only first access link for the operator. In production,
     * the private invitation is emailed by the VPS provisioning worker.
     */
    public function firstAccess(Company $company)
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless($company->provisioningJob?->status === 'ready', 404);
        $folder = realpath(dirname(base_path()).'/lumeron-'.$company->subdomain);
        abort_unless($folder && dirname($folder) === dirname(base_path())
            && !is_link($folder), 404);
        $marker = $folder.'/.lumeron-local-identity.json';
        abort_unless(is_file($marker) && !is_link($marker), 404);
        $identity = json_decode((string) file_get_contents($marker), true);
        abort_unless(is_array($identity)
            && ($identity['instance_id'] ?? '') === $company->instance_id
            && ($identity['cnpj'] ?? '') === $company->cnpj, 404);

        $path = $folder.'/storage/app/private/managed-first-access.json';
        abort_unless(is_file($path) && !is_link($path), 404);
        $invite = json_decode((string) file_get_contents($path), true);
        $url = $invite['url'] ?? null;
        abort_unless(is_string($url)
            && ($invite['email'] ?? '') === strtolower($company->email)
            && str_starts_with($url, 'http://lumeron-'.$company->subdomain.'.test/reset-password/'), 404);
        return redirect()->away($url);
    }

    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'min:3', 'max:220'],
            'trade_name' => ['nullable', 'string', 'max:220'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'installation_url' => $this->installationUrlRules(),
            'admin_login' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_.-]{3,100}$/'],
        ]);
        DB::transaction(function () use ($company, $data): void {
            $before = $company->only(array_keys($data));
            $company->update($data);
            if ($company->wasChanged()) {
                $company->record('updated', 'Dados cadastrais atualizados.', ['fields' => array_keys($data)]);
            }
        });
        return back()->with('success', 'Dados atualizados.');
    }

    public function note(Request $request, Company $company)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1500']]);
        $company->record('note', trim($data['note']));
        return back()->with('success', 'Anotação registrada no histórico.');
    }

}
