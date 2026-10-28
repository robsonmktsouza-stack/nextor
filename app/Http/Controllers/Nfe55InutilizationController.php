<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Nfe55Inutilization;
use App\Services\Fiscal\FiscalDocumentSettings;
use App\Services\Fiscal\Nfe55InutilizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class Nfe55InutilizationController extends Controller
{
    public function index()
    {
        return view('fiscal.nfe.inutilizations', [
            'tabs' => FiscalController::TABS,
            'rows' => Nfe55Inutilization::query()->latest('id')->paginate(25),
            'series' => (int) AppSetting::value('nfe', 'series', 1),
            'nextNumber' => (int) AppSetting::value('nfe', 'next_number', 1),
            'environment' => app(FiscalDocumentSettings::class)->environment('nfe'),
        ]);
    }

    public function store(Request $request, Nfe55InutilizationService $service)
    {
        abort_unless($request->user()?->canAccess('settings'), 403);
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'series' => ['required', 'integer', 'between:0,999'],
            'first_number' => ['required', 'integer', 'between:1,999999999'],
            'last_number' => ['required', 'integer', 'between:1,999999999', 'gte:first_number'],
            'reason' => ['required', 'string', 'min:15', 'max:255'],
            'confirmed_unused' => ['accepted'], 'current_password' => ['required', 'string'],
        ]);
        if (!Hash::check($data['current_password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Senha incorreta.']);
        }
        unset($data['current_password']);
        $service->request($data, (int) $request->user()->id);
        return redirect()->route('fiscal.nfe.inutilizations.index')
            ->with('success', 'Solicitação registrada. A inutilização depende da confirmação da SEFAZ.');
    }

    public function xml(Nfe55Inutilization $nfe55Inutilization)
    {
        $path = 'fiscal/nfe55/inutilizations/'.$nfe55Inutilization->id.'/authorized.xml';
        abort_unless($nfe55Inutilization->status === 'authorized' && $nfe55Inutilization->xml_path === $path
            && Storage::disk('local')->exists($path), 404);
        return Storage::disk('local')->download($path, 'Inutilizacao-NFe-'.(int)$nfe55Inutilization->year
            .'-'.(int)$nfe55Inutilization->series.'-'.(int)$nfe55Inutilization->first_number
            .'-'.(int)$nfe55Inutilization->last_number.'.xml', [
            'Content-Type' => 'application/xml', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function retry(Request $request, Nfe55Inutilization $nfe55Inutilization, Nfe55InutilizationService $service)
    {
        abort_unless($request->user()?->canAccess('settings'), 403);
        $data = $request->validate([
            'confirmed_unused' => ['accepted'], 'current_password' => ['required', 'string'],
        ]);
        if (!Hash::check($data['current_password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Senha incorreta.']);
        }
        $service->retry($nfe55Inutilization);
        return redirect()->route('fiscal.nfe.inutilizations.index')
            ->with('success', 'Solicitação original reagendada. Nenhum novo pedido de inutilização foi criado.');
    }
}
