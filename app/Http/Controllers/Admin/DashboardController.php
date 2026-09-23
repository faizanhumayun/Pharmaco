<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $businesses = Business::query()
            ->notArchived()
            ->withCount('activeMembers')
            ->latest()
            ->take(8)
            ->get();

        return view('admin.dashboard', [
            'businesses' => $businesses,
            'counts' => [
                'total' => Business::notArchived()->count(),
                'setup' => Business::where('status', BusinessStatus::Setup)->count(),
                'active' => Business::where('status', BusinessStatus::Active)->count(),
                'suspended' => Business::where('status', BusinessStatus::Suspended)->count(),
                'users' => User::where('is_active', true)->count(),
            ],
        ]);
    }
}
