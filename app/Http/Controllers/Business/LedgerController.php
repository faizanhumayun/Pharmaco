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
        $accounts = Account::forBusiness($business)
            ->with('children')
            ->orderBy('sort_order')
            ->get();

        return view('business.ledger.index', [
            'business' => $business,
            'asAt' => $asAt,
            'accounts' => $accounts->groupBy(fn (Account $a) => $a->type->value),
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
