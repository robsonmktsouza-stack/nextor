<?php

namespace App\Http\Controllers;

use App\Support\FiscalCodeCatalog;
use App\Models\FiscalCatalogEntry;
use App\Models\CompanySetting;
use App\Services\Fiscal\FiscalCatalogService;
use Illuminate\Http\JsonResponse;

final class FiscalCodeCatalogController extends Controller
{
    public function __invoke(FiscalCatalogService $catalog): JsonResponse
    {
        $catalogs = FiscalCodeCatalog::all();
        $release = $catalog->current();
        if ($release) {
            $day = now()->toDateString();
            $uf = strtoupper(trim((string) CompanySetting::query()->value('state')));
            foreach (array_keys($catalogs) as $kind) {
                $coverage = $release->coverage[$kind] ?? null;
                $complete = $coverage === 'complete'
                    || (is_array($coverage) && ($coverage['status'] ?? null) === 'complete');
                $scope = is_array($coverage) ? ($coverage['uf'] ?? null) : null;
                // A complete applicable snapshot replaces the old suggestions.
                // Keeping fallback rows here would revive removed/expired codes.
                if ($complete && ($scope === null || $scope === $uf)) $catalogs[$kind] = [];
            }
            // Only compact code lists belong in this shared endpoint. NCM/CEST
            // have their own paged lookup to avoid shipping thousands of rows.
            $entries = FiscalCatalogEntry::query()->where('release_id', $release->id)
                ->whereIn('kind', array_keys($catalogs))
                ->where(function ($query) use ($uf) {
                    $query->whereNull('uf');
                    if ($uf !== '') $query->orWhere('uf', $uf);
                })
                ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day))
                ->get(['kind', 'code', 'description', 'status']);
            foreach ($entries as $entry) {
                if ($entry->status === 'revoked') unset($catalogs[$entry->kind][$entry->code]);
                elseif ($entry->status === 'reference') $catalogs[$entry->kind][$entry->code] = $entry->description;
            }
        }
        return response()->json([
            'catalogs'=>$catalogs,
            'version'=>$release?->version ?? '2026-10-08',
            'reference_only'=>true,
            'cfop_note'=>'Lista de CFOPs frequentes; para outros códigos, utilize a digitação assistida.',
        ])->header('Cache-Control','private, max-age=300');
    }
}
