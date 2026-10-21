<?php

namespace App\Http\Controllers;

use App\Models\AutomationDelivery;
use App\Models\AutomationRule;
use App\Services\AutomationRunner;
use Illuminate\Http\Request;

final class AutomationController extends Controller
{
    public function index()
    {
        return view('automations.index', [
            'rules' => AutomationRule::query()->orderBy('id')->get(),
            'deliveries' => AutomationDelivery::query()->with(['company', 'rule'])
                ->latest('id')->limit(25)->get(),
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
        return back()->with('success', 'Regra atualizada. O próximo agendamento utilizará esses parâmetros.');
    }

    public function run(AutomationRunner $runner)
    {
        $summary = $runner->run();
        return back()->with('success',
            'Verificação concluída: '.$summary['rules'].' regra(s), '.$summary['new_events'].' novo(s) aviso(s) interno(s).');
    }
}
