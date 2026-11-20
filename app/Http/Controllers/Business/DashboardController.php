<?php

namespace App\Http\Controllers\Business;

use App\Domain\Reporting\DashboardQuery;
use App\Domain\Reporting\DayChecklist;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DailyEntry;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(Business $business, DashboardQuery $query, DayChecklist $checklist): View
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
            // Only how far through the day it is. The steps themselves are a
            // page of their own, and the dashboard is not a worklist.
            'day' => $checklist->for($business, $user),
            // Today, for quick access beside the figures: the counter's bills,
            // what came in from companies, and what was spent. Newest first.
            'todayBills' => \App\Models\PosBill::forBusiness($business)
                ->whereDate('business_date', $business->today()->toDateString())
                ->withCount('lines')
                ->latest('id')
                ->get(),
            'todayEntry' => DailyEntry::forBusiness($business)
                ->whereDate('business_date', $business->today()->toDateString())
                ->with(['purchaseLines.company', 'expenseLines.category'])
                ->first(),
            ...$query->build($business),
        ]);
    }
}
