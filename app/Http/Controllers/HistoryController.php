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
        $perPage = (int) $request->query('per_page', 20);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 20;
        return view('history', ['events' => $query->paginate($perPage)->withQueryString()]);
    }
}
