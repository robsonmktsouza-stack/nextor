<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use App\Models\FiscalTaxRule;
use App\Models\Product;
use App\Models\CompanySetting;
use App\Services\Fiscal\NFCeFiscalProfileService;
use App\Services\Fiscal\NFCeTaxRuleApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class FiscalTaxRuleController extends Controller
{
    public function index(Request $request, NFCeFiscalProfileService $profiles)
    {
        $preview = null;
        $previewError = null;
        $productId = $request->query('simulate_product_id');
        $previewDate = $request->query('simulate_date') ?: now()->toDateString();

        if ($productId !== null && $productId !== '') {
            $input = $request->validate([
                'simulate_product_id' => ['required', 'integer', 'exists:products,id'],
                'simulate_date' => ['nullable', 'date_format:Y-m-d'],
            ]);
            $product = Product::query()->findOrFail($input['simulate_product_id']);
            try {
                $company = CompanySetting::current();
                if ((string) $company->crt !== '1'
                    || strtoupper((string) $company->state) !== 'BA') {
                    throw new RuntimeException(
                        'Prévia disponível inicialmente para empresas do Simples Nacional na Bahia.'
                    );
                }
                $preview = $profiles->classifyProduct($product, $previewDate);
                $preview['product'] = $product;
            } catch (RuntimeException $exception) {
                $previewError = $exception->getMessage();
            }
        }

        return view('fiscal.rules.index', [
            'rules' => FiscalTaxRule::query()
                ->orderByDesc('is_active')->orderByDesc('priority')->orderBy('name')->get(),
            'useFiscalRules' => (bool) AppSetting::value('tax', 'use_fiscal_rules', false),
            'products' => Product::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'sku', 'ncm', 'fiscal_tax_group_id']),
            'preview' => $preview,
            'previewError' => $previewError,
            'previewProductId' => $productId,
            'previewDate' => $previewDate,
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

    public function mode(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', Rule::in(['0','1'])]]);
        AppSetting::put('tax', 'use_fiscal_rules', $data['enabled'] === '1');

        return back()->with('success', $data['enabled'] === '1'
            ? 'Regras fiscais ativadas: novos documentos precisam de uma regra aplicada antes da emissão.'
            : 'Modo de regras desativado: comportamento anterior preservado.');
    }

    public function apply(FiscalDocumentJob $fiscalDocumentJob, NFCeTaxRuleApplicationService $service)
    {
        try {
            $service->apply($fiscalDocumentJob->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha ao aplicar regras fiscais', [
                'fiscal_document_job_id' => $fiscalDocumentJob->id,
                'exception' => $e::class,
            ]);
            return back()->with('error', 'Não foi possível aplicar regras fiscais. Consulte o log do sistema.');
        }

        return back()->with('success', 'Regras fiscais aplicadas ao snapshot desta NFC-e. Confira os dados antes de transmitir.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required','string','max:160'],
            'document_type' => ['required',Rule::in(['nfce'])],
            'origin_uf' => ['required',Rule::in(['BA'])],
            'destination_uf' => ['required',Rule::in(['BA'])],
            'crt' => ['required',Rule::in(['1'])],
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
