<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PlanController extends Controller
{
    public function index()
    {
        return view('plans.index', [
            'plans' => Plan::query()->orderBy('id')->get(),
            'usage' => Company::query()->selectRaw('plan, COUNT(*) as total')->groupBy('plan')
                ->pluck('total', 'plan'),
        ]);
    }

    public function create()
    {
        return view('plans.form', ['plan' => new Plan()]);
    }

    public function edit(Plan $plan)
    {
        return view('plans.form', ['plan' => $plan]);
    }

    private function validated(Request $request, ?Plan $plan = null): array
    {
        $request->merge(['code' => strtolower(trim((string) $request->input('code')))]);
        return $request->validate([
            'code' => [
                'required', 'regex:/^[a-z][a-z0-9_-]{2,29}$/',
                Rule::unique('plans', 'code')->ignore($plan?->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'monthly_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'period_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['required', 'distinct', Rule::in(array_keys(config('license.modules')))],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['active'] = $request->boolean('active');
        $data['modules'] = array_values(array_unique($data['modules']));
        $plan = Plan::create($data);
        return redirect()->route('plans.index')->with('success',
            'Plano '.$plan->name.' criado. Já pode ser utilizado nas novas contas.');
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $this->validated($request, $plan);
        $data['active'] = $request->boolean('active');
        $data['modules'] = array_values(array_unique($data['modules']));
        DB::transaction(function () use ($plan, $data): void {
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->code !== $data['code']
                && Company::query()->where('plan', $plan->code)->exists()) {
                // Keep existing license codes, as the ERP receives their
                // stable identifiers rather than plan database IDs.
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'code' => 'Este código já pertence a clientes. Mantenha-o e altere somente o nome ou os recursos.',
                ]);
            }
            $plan->update($data);
        });
        return redirect()->route('plans.index')->with('success', 'Plano atualizado. Licenças existentes não foram alteradas.');
    }
}
