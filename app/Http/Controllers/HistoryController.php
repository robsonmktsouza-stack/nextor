<?php

namespace App\Http\Controllers;

use App\Models\LicenseEvent;
use Illuminate\Http\Request;

final class HistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = LicenseEvent::query()->with(['company', 'user'])->latest('id');
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->whereHas('company', fn ($q) => $q->where('legal_name', 'like', "%{$term}%")
                ->orWhere('subdomain', 'like', "%{$term}%"));
        }
        return view('history', ['events' => $query->paginate(25)->withQueryString()]);
    }
}
