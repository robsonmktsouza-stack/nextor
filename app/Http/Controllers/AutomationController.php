<?php

namespace App\Http\Controllers;

use App\Models\AutomationDelivery;
use App\Models\AutomationRule;
use App\Services\AutomationRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AutomationController extends Controller
{
    public function index(Request $request)
    {
        $rules = AutomationRule::query();
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $rules->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%'));
        }
        if (in_array($request->query('status'), ['enabled', 'disabled'], true)) {
            $rules->where('enabled', $request->query('status') === 'enabled');
        }
        $perPage = (int) $request->query('per_page', 20);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 20;
        return view('automations.index', [
            'rules' => $rules->orderBy('id')->paginate($perPage, ['*'], 'rules_page')->withQueryString(),
            'deliveries' => AutomationDelivery::query()->with(['company', 'rule'])
                ->latest('id')->paginate($perPage, ['*'], 'alerts_page')->withQueryString(),
        ]);
    }

    public function create()
    {
        return view('automations.form', ['rule' => new AutomationRule()]);
    }

    public function edit(AutomationRule $rule)
    {
        return view('automations.form', ['rule' => $rule]);
    }

    private function validated(Request $request, ?AutomationRule $rule = null): array
    {
        $data = $request->validate([
            'name' => [$rule ? 'sometimes' : 'required', 'required', 'string', 'max:100'],
            'description' => [$rule ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'kind' => [$rule ? 'prohibited' : 'required', Rule::in(array_keys(AutomationRule::KINDS))],
            'days' => ['required', 'integer', 'min:0', 'max:365'],
            'enabled' => ['required', 'boolean'],
        ]);
        $kind = $rule?->kindCode() ?? $data['kind'];
        if ($kind === 'offline' && (int) $data['days'] < 1) {
            throw ValidationException::withMessages([
                'days' => 'Informe pelo menos 1 dia sem comunicação.',
            ]);
        }
        $data['days'] = $kind === 'expired' ? 0 : (int) $data['days'];
        $data['enabled'] = $request->boolean('enabled');
        unset($data['kind']);
        if (!$rule) $data['kind'] = $kind;

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $rule = new AutomationRule($data);
        $rule->code = 'custom_'.Str::lower(Str::random(20));
        $rule->save();

        return redirect()->route('automations.index')->with('success', 'Automação criada.');
    }

    public function update(Request $request, AutomationRule $rule)
    {
        $rule->update($this->validated($request, $rule));
        return redirect()->route('automations.index')->with('success', 'Automação atualizada.');
    }

    public function run(AutomationRunner $runner)
    {
        $summary = $runner->run();
        return back()->with('success',
            'Verificação concluída. Novos avisos: '.$summary['new_events'].'.');
    }
}
