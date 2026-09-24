<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Business;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
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

        return view('business.ledger.index', [
            'business' => $business,
            'asAt' => $asAt,
            'accounts' => $accounts->groupBy(fn (Account $a) => $a->type->value),
            'opened' => $opened,
            'children' => $children,
            'balances' => $balances->all($business, $asAt),
            'position' => $balances->position($business, $asAt),
            'trial' => $balances->trialBalance($business, $asAt),
            'types' => AccountType::cases(),
            'at' => $at,
            'points' => self::POINTS,
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

    public function show(Business $business, string $code, BalanceService $balances): View
    {
        $this->authorize('viewLedger', $business);

        $account = Account::forBusiness($business)->code($code)->firstOrFail();

        $accountIds = Account::forBusiness($business)
            ->where('id', $account->id)
            ->orWhere('parent_id', $account->id)
            ->pluck('id');

        $entries = LedgerEntry::forBusiness($business)
            ->whereIn('account_id', $accountIds)
            ->with(['transaction.creator', 'account'])
            ->whereHas('transaction', fn ($q) => $q->where('status', '!=', 'draft'))
            ->orderBy('business_date')
            ->orderBy('id')
            ->get();

        // The running balance is computed here rather than in SQL so it goes
        // through Money and cannot drift from the summed balance.
        $running = Money::zero();
        $sign = $account->type->presentationSign();

        $rows = $entries->map(function (LedgerEntry $entry) use (&$running, $sign) {
            $running = $running->plus($entry->signedAmount()->times($sign));

            return ['entry' => $entry, 'running' => $running];
        });

        return view('business.ledger.show', [
            'business' => $business,
            'account' => $account,
            'rows' => $rows,
            'closing' => $balances->asAt($business, $code),
        ]);
    }
}
