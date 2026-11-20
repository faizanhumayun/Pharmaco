<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Domain\Reporting\AlertEngine;
use App\Domain\Reporting\DayChecklist;
use App\Enums\AccountCode;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DailyEntry;
use Illuminate\Contracts\View\View;

/**
 * What to do.
 *
 * The dashboard says where the business stands; this says what somebody has to
 * act on. They are different questions and were crowding each other on one
 * page, so this is the second one on its own.
 *
 * Nothing here is stored and nothing is dismissible. Every item is calculated
 * from the ledger each time the page is opened, which means an item leaves only
 * when the thing it is about has actually been dealt with — never because
 * somebody clicked it away.
 */
class AttentionController extends Controller
{
    public function __invoke(
        Business $business,
        AlertEngine $alerts,
        BalanceService $balances,
        DayChecklist $checklist,
    ): View
    {
        $this->authorize('view', $business);

        $user = auth()->user();

        /*
         * The alerts quote the day's money — receivables against sales, cash
         * cover, collections. A role that is not shown the day's figures on the
         * dashboard is not shown them here either, so somebody who only books
         * orders gets the actions and nothing else.
         */
        $seesTheMoney = $user->can('viewAny', [DailyEntry::class, $business]);

        /*
         * Deliberately not the whole DashboardQuery: this page needs the alerts
         * and one balance, and running the full position for it would make a
         * page that is opened often pay for figures it does not show.
         */
        return view('business.attention', [
            'business' => $business,
            'seesTheMoney' => $seesTheMoney,
            'day' => $checklist->for($business, $user),
            'alerts' => $seesTheMoney ? $alerts->for($business) : [],
            // Same rule as the dashboard: an operator is not shown the
            // business's standing, so they are not shown unexplained equity
            // either — it is not theirs to answer for.
            'showPosition' => $user->can('viewReports', $business),
            'openingEquity' => $user->can('viewReports', $business)
                ? $balances->asAt($business, AccountCode::OpeningBalanceEquity, $business->today())
                : \App\Support\Money::zero(),
        ]);
    }
}
