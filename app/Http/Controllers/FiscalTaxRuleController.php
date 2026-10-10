<?php

namespace App\Http\Controllers;

use App\Models\FiscalTaxRule;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FiscalTaxRuleController extends Controller
{
    private const UFS=['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    public function index()
    {
        return view('fiscal.rules.index', [
            'rules' => FiscalTaxRule::query()
                ->orderByDesc('is_active')->orderByDesc('priority')->orderBy('name')->get(),
            'products' => Product::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'sku']),
            'ufs' => self::UFS,
            'issuerUf' => strtoupper((string)\App\Models\CompanySetting::current()->state),
            'issuerCrt' => (string)\App\Models\CompanySetting::current()->crt,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        FiscalTaxRule::query()->create($data);

        return back()->with('success', 'Regra fiscal cadastrada. Confira os critérios antes de ativar sua utilização.');
    }

    public function update(Request $request, FiscalTaxRule $fiscalTaxRule)
    {
        $data = $this->validated($request);
        $data['revision'] = $fiscalTaxRule->revision + 1;
        $fiscalTaxRule->update($data);

        return back()->with('success', 'Regra fiscal atualizada. Documentos já preparados mantêm o snapshot anterior até a aplicação explícita.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required','string','max:160'],
            'document_type' => ['required',Rule::in(['nfce'])],
            'origin_uf' => ['required',Rule::in(self::UFS)],
            'destination_uf' => ['required',Rule::in(self::UFS)],
            'crt' => ['required',Rule::in(['1','4'])],
            'product_id' => ['nullable','integer','exists:products,id'],
            'ncm_prefix' => ['nullable','regex:/^[0-9]{2,8}$/'],
            'cfop' => ['required','regex:/^5[0-9]{3}$/'],
            'csosn' => ['required','regex:/^[0-9]{3}$/'],
            'pis_cst' => ['required','regex:/^[0-9]{2}$/'],
            'cofins_cst' => ['required','regex:/^[0-9]{2}$/'],
            'priority' => ['required','integer','between:-100,100'],
            'valid_from' => ['nullable','date'],
            'valid_until' => ['nullable','date'],
            'notes' => ['nullable','string','max:2000'],
        ]);
        if ($data['origin_uf'] !== $data['destination_uf']) {
            throw ValidationException::withMessages([
                'destination_uf'=>'A NFC-e deste fluxo admite somente operação interna: selecione a mesma UF de origem e destino.',
            ]);
        }
        if (!empty($data['valid_from']) && !empty($data['valid_until'])
            && $data['valid_until'] < $data['valid_from']) {
            throw ValidationException::withMessages([
                'valid_until' => 'O fim da vigência não pode ser anterior ao início.',
            ]);
        }

        $data['ncm_prefix'] = trim((string) ($data['ncm_prefix'] ?? '')) ?: null;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
