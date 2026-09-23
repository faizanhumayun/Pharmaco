<?php

namespace App\Http\Controllers\Business;

use App\Domain\Reporting\DashboardQuery;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DailyEntry;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(Business $business, DashboardQuery $query): View
    {
        $this->authorize('view', $business);

        if (! $business->acceptsTransactions()) {
            return view('business.workspace', ['business' => $business]);
        }

        $user = auth()->user();

        // The dashboard is the day's money. Someone whose role never touches the
        // day's figures — an order booker — lands on a page of what they can do.
        if (! $user->can('viewAny', [DailyEntry::class, $business])) {
            return view('business.home', [
                'business' => $business,
                'role' => $user->roleIn($business),
            ]);
        }

        return view('business.dashboard', [
            'business' => $business,
            // Operators deliberately do not see the overall position: they do
            // not need the business's financial standing to enter the day.
            'showPosition' => $user->can('viewReports', $business),
            ...$query->build($business),
        ]);
    }
}
