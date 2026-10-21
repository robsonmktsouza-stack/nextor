<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use App\Support\Cnpj;
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

                if ($scheme === 'http'
                    && app()->environment('local', 'testing')
                    && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    return;
                }

                $fail('O endereço da instalação deve usar HTTPS. Para testar no Laragon, use http://127.0.0.1:8001.');
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

    public function store(Request $request)
    {
        $request->merge([
            'cnpj' => Cnpj::digits($request->input('cnpj')),
            'subdomain' => strtolower(trim((string) $request->input('subdomain'))),
        ]);
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'min:3', 'max:220'],
            'trade_name' => ['nullable', 'string', 'max:220'],
            'cnpj' => ['required', 'digits:14', Rule::unique('companies', 'cnpj'),
                fn ($field, $value, $fail) => Cnpj::valid($value) ?: $fail('CNPJ inválido.')],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'subdomain' => ['required', 'regex:/^[a-z][a-z0-9-]{1,38}$/',
                Rule::notIn(['admin', 'api', 'login', 'www']), Rule::unique('companies', 'subdomain')],
            'installation_url' => $this->installationUrlRules(),
            'admin_login' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_.-]{3,100}$/'],
            'plan' => ['required', Rule::exists('plans', 'code')->where('active', true)],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'modules' => ['nullable', 'array'],
            'modules.*' => [Rule::in(array_keys(config('license.modules')))],
        ]);
        $plan = Plan::query()->where('code', $data['plan'])->firstOrFail();
        $data['modules'] = array_values(array_unique($data['modules'] ?? $plan->modules ?? []));
        $secret = bin2hex(random_bytes(48));
        $company = DB::transaction(function () use ($data, $secret): Company {
            $company = Company::create([
                ...$data,
                'instance_id' => (string) Str::uuid(),
                'license_secret' => $secret,
                'license_status' => 'active',
                'modules' => $data['modules'],
            ]);
            $company->record('created', 'Conta cadastrada e licença criada.');
            return $company;
        });
        return redirect()->route('clients.show', $company)
            ->with('success', 'Conta cadastrada e acesso liberado no painel. A instalação será sincronizada pelo gerenciador de implantação.');
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
