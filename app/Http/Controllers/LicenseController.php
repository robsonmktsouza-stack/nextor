<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class LicenseController extends Controller
{
    public function index(Request $request)
    {
        return app(CompanyController::class)->listing($request, true);
    }

    public function status(Request $request, Company $company)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'suspend'])],
            'reason' => ['nullable', 'string', 'max:250'],
        ]);
        DB::transaction(function () use ($company, $data): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $next = $data['action'] === 'activate' ? 'active' : 'suspended';
            if ($company->license_status === $next) {
                return;
            }
            $company->update(['license_status' => $next]);
            $company->record($next === 'active' ? 'activated' : 'suspended',
                ($next === 'active' ? 'Acesso reativado.' : 'Acesso suspenso.')
                . (!empty($data['reason']) ? ' Motivo: '.$data['reason'] : ''));
        });
        return back()->with('success', 'Situação da licença atualizada.');
    }

    public function renew(Request $request, Company $company)
    {
        $data = $request->validate(['months' => ['required', 'integer', Rule::in([1, 3, 6, 12])]]);
        DB::transaction(function () use ($company, $data): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $base = $company->expires_at && $company->expires_at->isFuture()
                ? $company->expires_at->copy()
                : today();
            $newDate = $base->addMonthsNoOverflow((int) $data['months']);
            $company->update(['expires_at' => $newDate]);
            $company->record('renewed', 'Licença renovada por '.$data['months'].' mês(es).',
                ['expires_at' => $newDate->toDateString()]);
        });
        return back()->with('success', 'Validade renovada.');
    }

    public function modules(Request $request, Company $company)
    {
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'code')],
            'modules' => ['nullable', 'array'],
            'modules.*' => [Rule::in(array_keys(config('license.modules')))],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $plan = Plan::query()->where('code', $data['plan'])->firstOrFail();
        if (!$plan->active && $company->plan !== $plan->code) {
            return back()->withErrors(['plan' => 'Este plano está arquivado. Selecione um plano disponível.']);
        }
        DB::transaction(function () use ($company, $data, $plan): void {
            $company->update([
                'plan' => $data['plan'],
                'modules' => array_values(array_unique($data['modules'] ?? $plan->modules ?? [])),
                'expires_at' => $data['expires_at'] ?? null,
            ]);
            $company->record('license_changed', 'Plano, módulos ou validade alterados.');
        });
        return back()->with('success', 'Licença atualizada.');
    }
}
