<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FiscalCatalogEntry;
use App\Models\FiscalCatalogRelease;
use App\Services\Fiscal\FiscalCatalogService;
use App\Services\Fiscal\FiscalCatalogTemplateService;
use App\Services\Fiscal\FiscalCatalogCoverage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FiscalCatalogController extends Controller
{
    public const KINDS = [
        'cfop' => 'CFOP', 'ncm' => 'NCM', 'cest' => 'CEST',
        'icms_cst' => 'CST ICMS', 'csosn' => 'CSOSN',
        'pis_cofins_cst' => 'CST PIS / COFINS', 'ipi_cst' => 'CST IPI',
        'ibs_cbs_cst' => 'CST IBS / CBS', 'ibs_cbs_class' => 'Classificações IBS / CBS',
        'benefit' => 'Benefícios fiscais', 'origin' => 'Origem da mercadoria', 'crt' => 'CRT',
        'operation_nature' => 'Modelos de operação', 'tax_group' => 'Modelos de grupo',
        'tax_parameter' => 'Parâmetros tributários',
    ];

    public function index(Request $request, FiscalCatalogService $catalog)
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(self::KINDS))],
            'q' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'uf' => ['nullable', Rule::in(FiscalCatalogCoverage::UFS)],
            'status' => ['nullable', Rule::in(['reference', 'template', 'revoked'])],
            'release' => ['nullable', 'integer', Rule::exists('fiscal_catalog_releases', 'id')],
            'tab' => ['nullable', Rule::in(['entries', 'history', 'coverage'])],
        ]);
        $release = isset($filters['release'])
            ? FiscalCatalogRelease::query()->findOrFail($filters['release']) : $catalog->current();
        $kind = $filters['kind'] ?? 'cfop';
        $query = FiscalCatalogEntry::query()->where('release_id', $release?->id ?? 0)->where('kind', $kind);
        if (!empty($filters['q'])) {
            $term = $filters['q'];
            $query->where(fn ($q) => $q->where('code', 'like', '%'.$term.'%')
                ->orWhere('description', 'like', '%'.$term.'%'));
        }
        if (!empty($filters['date'])) {
            $date = $filters['date'];
            $query->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date));
        }
        if (!empty($filters['uf'])) {
            $query->where(fn ($q) => $q->whereNull('uf')->orWhere('uf', $filters['uf']));
        }
        if (!empty($filters['status'])) $query->where('status', $filters['status']);

        $coverageDate = $filters['date'] ?? $release?->consulted_at?->format('Y-m-d') ?? now()->toDateString();
        $coverageByUf = [];
        foreach (FiscalCatalogCoverage::UFS as $uf) {
            $coverageByUf[$uf] = [];
            foreach (['ncm','cfop','cest','benefit'] as $domain) {
                $coverageByUf[$uf][$domain] = FiscalCatalogCoverage::scope($release, $domain, $uf, $coverageDate);
            }
        }
        return view('fiscal.catalog.index', [
            'release' => $release, 'current' => $catalog->current(),
            'releases' => FiscalCatalogRelease::query()->orderByDesc('id')->get(),
            'entries' => $query->orderBy('code')->paginate(AppSetting::tablePerPage($request))->withQueryString(),
            'kind' => $kind, 'kinds' => self::KINDS, 'tab' => $filters['tab'] ?? 'entries',
            'coverageByUf' => $coverageByUf, 'coverageDate' => $coverageDate,
        ]);
    }

    public function install(Request $request, FiscalCatalogService $catalog, FiscalCatalogTemplateService $templates)
    {
        $request->validate(['install_templates' => ['nullable', 'boolean']]);
        $release = $catalog->installBundled();
        if ($request->boolean('install_templates')) $templates->install($release->version);
        return redirect()->route('fiscal.catalog.index')->with('success', 'Catálogo '.$release->version.' instalado.');
    }

    public function import(Request $request, FiscalCatalogService $catalog)
    {
        $request->validate([
            'catalog_file' => ['required', 'file', 'max:32768', 'extensions:json'],
            'sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        $bytes = $request->file('catalog_file')->getContent();
        if (!hash_equals(strtolower($request->string('sha256')->toString()), hash('sha256', $bytes))) {
            throw ValidationException::withMessages(['sha256' => 'O SHA-256 não corresponde ao arquivo aprovado.']);
        }
        try {
            $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['catalog_file' => 'O arquivo deve conter um catálogo JSON válido.']);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw ValidationException::withMessages(['catalog_file' => 'O catálogo deve conter um objeto JSON versionado.']);
        }
        $release = $catalog->import($manifest);

        return redirect()->route('fiscal.catalog.index', ['release' => $release->id, 'tab' => 'history'])
            ->with('success', 'Catálogo '.$release->version.' importado.');
    }
}
