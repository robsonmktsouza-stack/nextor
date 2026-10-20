<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\LicenseEvent;
use Illuminate\Support\Carbon;

final class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today()->toDateString();
        return view('dashboard', [
            'total' => Company::count(),
            'active' => Company::where('license_status', 'active')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today))->count(),
            'suspended' => Company::where('license_status', 'suspended')->count(),
            'expired' => Company::where('license_status', 'active')->whereDate('expires_at', '<', $today)->count(),
            'renewals' => Company::where('license_status', 'active')
                ->whereBetween('expires_at', [$today, Carbon::today()->addDays(15)->toDateString()])
                ->orderBy('expires_at')->limit(8)->get(),
            'events' => LicenseEvent::with(['company', 'user'])->latest('created_at')->limit(7)->get(),
        ]);
    }
}
