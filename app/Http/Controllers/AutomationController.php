<?php

namespace App\Http\Controllers;

use App\Models\AutomationDelivery;
use App\Models\AutomationRule;
use App\Services\AutomationRunner;
use Illuminate\Http\Request;

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

    public function update(Request $request, AutomationRule $rule)
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:0', 'max:365'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $days = $rule->code === 'expired' ? 0 : (int) $data['days'];
        if ($rule->code === 'offline' && $days < 1) {
            return back()->withErrors(['days' => 'A ausência de comunicação precisa de pelo menos um dia.']);
        }
        $rule->update(['days' => $days, 'enabled' => $request->boolean('enabled')]);
        return back()->with('success', 'Automação atualizada.');
    }

    public function run(AutomationRunner $runner)
    {
        $summary = $runner->run();
        return back()->with('success',
            'Verificação concluída. Novos avisos: '.$summary['new_events'].'.');
    }
}
