<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Business;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * A read-only window on the ledger.
 *
 * Phase 3 has no data-entry screens — its job is the engine. This exists so the
 * numbers can be inspected and drilled into, which is also the standard the
 * dashboard is held to later: a figure you cannot drill into is a figure nobody
 * trusts.
 */
class LedgerController extends Controller
{
    public function index(Request $request, Business $business, BalanceService $balances): View
    {
        // The ledger shows the whole position, so it follows the same rule as
        // the dashboard's position band: owners and the App Owner, not operators.
        $this->authorize('viewLedger', $business);

        [$at, $asAt] = $this->asAt($business, $request);

        // Explicit business scoping even inside the workspace: the balance
        // service and these queries never rely on the global scope alone.
        /*
         * The chart itself, not every ledger under it. A business with three
         * hundred customers has three hundred sub-accounts, and listing them
         * all makes the one page that shows the position the slowest in the
         * app. Each control account says how many it has; opening one lists
         * them, a page at a time.
         */
        $accounts = Account::forBusiness($business)
            ->withCount('children')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        // The sub-accounts of one control account, when asked for.
        $opened = $request->filled('under')
            ? Account::forBusiness($business)->where('code', $request->string('under')->toString())->first()
            : null;

        $children = $opened
            ? Account::forBusiness($business)
                ->where('parent_id', $opened->id)
                ->orderBy('code')
                ->paginate(50)
                ->withQueryString()
            : null;

        /*
         * Sub-accounts, as names only. Their balances are already in the one
         * query BalanceService::all() runs for the whole chart, so the page
         * can show the few that hold something under their own row without
         * drawing — or paging through — the hundreds that hold nothing.
         */
        $subs = Account::forBusiness($business)
            ->whereNotNull('parent_id')
            ->get(['id', 'code', 'name', 'parent_id'])
            ->groupBy('parent_id');

        // The day before, to say whether each figure went up or down. Not
        // before the business opened: there is nothing to compare with.
        $dayBefore = $asAt->copy()->subDay();
        $comparable = ! $business->opening_date || $dayBefore->greaterThanOrEqualTo(Carbon::parse($business->opening_date->toDateString()));

        return view('business.ledger.index', [
            'compareDate' => $comparable ? $dayBefore : null,
            'balancesBefore' => $comparable ? $balances->all($business, $dayBefore) : null,
            'positionBefore' => $comparable ? $balances->position($business, $dayBefore) : null,
            'business' => $business,
            'asAt' => $asAt,
            'accounts' => $accounts->groupBy(fn (Account $a) => $a->type->value),
            'subs' => $subs,
            'opened' => $opened,
            'children' => $children,
            'balances' => $balances->all($business, $asAt),
            'position' => $balances->position($business, $asAt),
            'trial' => $balances->trialBalance($business, $asAt),
            'at' => $at,
            'points' => self::POINTS,
            // Carried onto every account link, so a statement opens as at the
            // same day the page is showing.
            'dateQuery' => $at === 'today' ? [] : ['as_at' => $asAt->toDateString()],
        ]);
    }

    /** Points in time an owner asks about, rather than a date picker alone. */
    private const POINTS = [
        'today' => 'Today',
        'yesterday' => 'End of yesterday',
        'last_month' => 'End of last month',
        'opening' => 'Opening day',
        'custom' => 'Pick a date',
    ];

    /**
     * The day the balances are read at. A plain as_at date (older links) is
     * treated as a picked date.
     *
     * @return array{0: string, 1: Carbon}
     */
    private function asAt(Business $business, Request $request): array
    {
        $today = Carbon::parse($business->today()->toDateString());

        $at = $request->string('at')->toString();
        $at = array_key_exists($at, self::POINTS) ? $at : ($request->filled('as_at') ? 'custom' : 'today');

        $date = match ($at) {
            'yesterday' => $today->copy()->subDay(),
            'last_month' => $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay(),
            'opening' => $business->opening_date ? Carbon::parse($business->opening_date->toDateString()) : $today,
            'custom' => $request->date('as_at') ? Carbon::parse($request->date('as_at')->toDateString()) : $today,
            default => $today,
        };

        return [$at, $date->greaterThan($today) ? $today : $date];
    }

    /** Entries on one page of a statement. */
    private const PER_PAGE = 100;

    public function show(Request $request, Business $business, string $code, BalanceService $balances): View
    {
        $this->authorize('viewLedger', $business);

        $account = Account::forBusiness($business)->code($code)->firstOrFail();

        // The day the ledger page was showing, when the link came from there.
        $today = Carbon::parse($business->today()->toDateString());
        $asAt = $request->date('as_at') ? Carbon::parse($request->date('as_at')->toDateString()) : null;
        $asAt = $asAt && $asAt->lessThan($today) ? $asAt : null;

        $accountIds = Account::forBusiness($business)
            ->where('id', $account->id)
            ->orWhere('parent_id', $account->id)
            ->pluck('id');

        /*
         * Every entry's two amounts and nothing else. The running balance has
         * to be worked out from the first entry, so all of them are read — but
         * only the page being shown is loaded in full, with its transaction
         * and who entered it.
         */
        $amounts = LedgerEntry::forBusiness($business)
            ->whereIn('account_id', $accountIds)
            ->posted()
            ->when($asAt, fn ($q) => $q->whereDate('business_date', '<=', $asAt->toDateString()))
            ->orderBy('business_date')
            ->orderBy('id')
            ->get(['id', 'debit', 'credit']);

        // The running balance is computed here rather than in SQL so it goes
        // through Money and cannot drift from the summed balance.
        $running = Money::zero();
        $sign = $account->type->presentationSign();

        $balanceAfter = [];

        foreach ($amounts as $entry) {
            $running = $running->plus($entry->signedAmount()->times($sign));
            $balanceAfter[$entry->id] = $running;
        }

        // Newest first on the screen. The running balance was worked out oldest
        // first, as it has to be, so each row still shows the balance as it
        // stood after that entry.
        $page = max(1, (int) $request->integer('page', 1));
        $ids = array_slice(array_reverse(array_keys($balanceAfter)), ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $entries = LedgerEntry::forBusiness($business)
            ->whereIn('id', $ids)
            ->with(['transaction.creator', 'account'])
            ->get()
            ->keyBy('id');

        $rows = collect($ids)
            ->filter(fn ($id) => $entries->has($id))
            ->map(fn ($id) => ['entry' => $entries[$id], 'running' => $balanceAfter[$id]])
            ->values();

        return view('business.ledger.show', [
            'business' => $business,
            'account' => $account,
            'asAt' => $asAt,
            'rows' => new LengthAwarePaginator($rows, count($balanceAfter), self::PER_PAGE, $page, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]),
            'closing' => $balances->asAt($business, $code, $asAt),
        ]);
    }
}
