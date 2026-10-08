<?php

namespace App\Http\Controllers;

use App\Support\FiscalCodeCatalog;
use Illuminate\Http\JsonResponse;

final class FiscalCodeCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'catalogs'=>FiscalCodeCatalog::all(),
            'version'=>'2026-10-08',
            'cfop_note'=>'Lista de CFOPs frequentes; para outros códigos, utilize a digitação assistida.',
        ])->header('Cache-Control','private, max-age=3600');
    }
}
