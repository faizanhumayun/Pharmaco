<?php

namespace App\Http\Controllers\Business;

use App\Domain\Closing\ClosingGuard;
use App\Domain\Closing\DailyClosingCalculator;
use App\Domain\Ledger\BalanceService;
use App\Domain\Market\Outstanding;
use App\Domain\Reporting\ChangeHistory;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\DailyEntry;
use App\Models\PosBill;
use App\Models\PosReturn;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * The Daily Log: what happened on a day, read only.
 *
 * Nothing is typed here. Every bill, delivery, collection, supplier payment
 * and expense writes itself into its day as it happens (DayWriter), so this
 * page only shows the day — its totals, and each list on its own tab.
 */
class DailyEntryController extends Controller
{
    /** The sections down the side of a day, in order. */
    private const SECTIONS = ['totals', 'standing', 'sales', 'purchases', 'collections', 'payments', 'expenses', 'history'];

    /**
     * Every day, newest first: what was traded, where the balances ended and
     * whether the day is closed — the Daily Log and the closings in one list.
     *
     * A day is listed when anything was recorded on it or it was closed. A
     * closed day shows the figures it was closed with; an open one is worked
     * out now, so it is never blank.
     */
    public function index(Request $request, Business $business, DailyClosingCalculator $calculator): View
    {
        $this->authorize('viewAny', [DailyEntry::class, $business]);

        // Either end may be left open: "from the 1st", "up to the 15th".
        $from = $request->date('from');
        $to = $request->date('to');
        $status = in_array($request->query('status'), ['closed', 'open'], true) ? $request->query('status') : null;
        $seesClosing = $request->user()->can('viewAny', [DailyClosing::class, $business]);

        $closedDates = DailyClosing::forBusiness($business)->where('status', 'finalized')
            ->pluck('business_date')->map(fn ($d) => $d->toDateString())->all();

        $allDates = DailyEntry::forBusiness($business)->pluck('business_date')
            ->merge(DailyClosing::forBusiness($business)->pluck('business_date'))
            ->map(fn ($d) => $d->toDateString())
            ->unique();

        $dates = $allDates
            ->when($from, fn ($c) => $c->filter(fn ($d) => $d >= $from->toDateString()))
            ->when($to, fn ($c) => $c->filter(fn ($d) => $d <= $to->toDateString()))
            ->when($status === 'closed', fn ($c) => $c->filter(fn ($d) => in_array($d, $closedDates, true)))
            // Still to close: after the opening day, and not closed yet.
            ->when($status === 'open', fn ($c) => $c->filter(fn ($d) => ! in_array($d, $closedDates, true)
                && ($business->opening_date === null || $d > $business->opening_date->toDateString())))
            ->sortDesc()
            ->values();

        $perPage = 30;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $pageDates = $dates->forPage($page, $perPage)->values();

        $entries = DailyEntry::forBusiness($business)
            ->whereIn('business_date', $pageDates)
            ->with(['purchaseLines'])
            ->get()
            ->keyBy(fn ($e) => $e->business_date->toDateString());

        $closings = $seesClosing
            ? DailyClosing::forBusiness($business)->whereIn('business_date', $pageDates)->with('finalizer')->get()
                ->keyBy(fn ($c) => $c->business_date->toDateString())
            : collect();

        $days = $pageDates->map(function (string $date) use ($business, $entries, $closings, $seesClosing, $calculator) {
            $closing = $closings->get($date);

            return [
                'date' => Carbon::parse($date),
                'entry' => $entries->get($date),
                'closing' => $closing,
                // Closed: as it was closed. Otherwise as it stands now.
                'figures' => ! $seesClosing ? null : ($closing?->isFinalized() ? null : $calculator->compute($business, Carbon::parse($date))),
            ];
        });

        return view('business.daily.index', [
            'business' => $business,
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'total' => $allDates->count(),
            'seesProfit' => $request->user()->can('viewReports', $business),
            'seesClosing' => $seesClosing,
            'days' => new LengthAwarePaginator($days, $dates->count(), $perPage, $page, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]),
        ]);
    }

    /** One day: its totals, and each list on its own section. */
    public function day(
        Request $request,
        Business $business,
        Outstanding $outstanding,
        ChangeHistory $history,
        DailyClosingCalculator $calculator,
        ClosingGuard $guard,
        BalanceService $balances,
        ?string $date = null,
    ): View
    {
        $this->authorize('viewAny', [DailyEntry::class, $business]);

        $today = $business->today();
        $day = $date !== null ? Carbon::parse($date, $business->timezone)->startOfDay() : $today->copy();

        // Never a day still to come.
        if ($day->greaterThan($today)) {
            $day = $today->copy();
        }

        // The closing of the day lives here too: where the business stands,
        // and the count and close behind the Close day button.
        $seesClosing = $request->user()->can('viewAny', [DailyClosing::class, $business]);

        $section = in_array($request->query('section'), self::SECTIONS, true) ? $request->query('section') : 'totals';
        if ($section === 'standing' && ! $seesClosing) {
            $section = 'totals';
        }

        $entry = DailyEntry::forBusiness($business)
            ->where('business_date', $day->toDateString())
            ->with([
                'purchaseLines.company', 'expenseLines.category',
                'collectionLines.pharmacy', 'collectionLines.allocations.bill',
            ])
            ->first();

        // The till's bills of the day, each with what is still owed on it.
        $collected = $outstanding->collectedByBill($business);
        $bills = PosBill::forBusiness($business)
            ->whereDate('business_date', $day->toDateString())
            ->with('returns')
            ->withCount('lines')
            ->orderBy('id')
            ->get()
            ->each(function (PosBill $bill) use ($collected, $outstanding) {
                $bill->setAttribute('collected', $collected[$bill->id] ?? Money::zero());
                $bill->setAttribute('owing', $outstanding->onBill($bill));
            });

        $returns = PosReturn::forBusiness($business)
            ->whereDate('business_date', $day->toDateString())
            ->with('bill')
            ->orderBy('id')
            ->get();

        // A delivery's invoice has an amount; money paid to a company against
        // what it was already owed has none, only the payment.
        $lines = $entry?->purchaseLines ?? collect();
        $purchases = $lines->filter(fn ($l) => $l->amount->isPositive())->values();
        $payments = $lines->filter(fn ($l) => $l->amount->isZero() && $l->paid->isPositive())->values();

        $zero = Money::zero();
        $totals = [
            'sales' => $entry?->totalSales() ?? $zero,
            'sales_received' => $entry?->sale_cash ?? $zero,
            'cogs' => $entry?->costOfGoodsSold() ?? $zero,
            'gross_profit' => $entry?->gross_profit ?? $zero,
            'margin' => $entry?->marginPercent(),
            'purchases' => Money::sum($purchases->pluck('amount')),
            'purchases_paid' => Money::sum($purchases->pluck('paid')),
            // Still owed on today's own invoices; paying more than one cost is
            // a payment against earlier bills, not a negative here.
            'purchases_pending' => Money::sum($purchases->map(fn ($l) => $l->pending()->isNegative() ? Money::zero() : $l->pending())),
            'collected' => Money::sum(($entry?->collectionLines ?? collect())->pluck('amount')),
            // Paid against earlier bills: today's payment-only lines, what was
            // paid on today's invoices beyond them, and the older single field.
            'paid_to_suppliers' => $entry !== null
                ? $entry->purchaseExcess()->plus($entry->company_payment_cash)
                : $zero,
            'expenses' => $entry?->expenses_cash ?? $zero,
            'net_profit' => $entry?->netProfit() ?? $zero,
            'returns' => Money::sum($returns->pluck('total')),
        ];

        $closing = $seesClosing ? DailyClosing::forBusiness($business)
            ->where('business_date', $day->toDateString())
            ->first() : null;

        return view('business.daily.day', [
            'business' => $business,
            'seesClosing' => $seesClosing,
            // Everything the Business standing section and the Close day
            // window need. Not computed for someone who cannot see closings.
            'close' => $seesClosing ? [
                'closing' => $closing,
                'figures' => $calculator->compute($business, $day),
                // Where the business stood the night before, for the change
                // on the Net position card.
                'openingNetPosition' => $balances->position($business, $day->copy()->subDay())->netPosition(),
                'openingCashCorrection' => $calculator->openingCashCorrection($business, $day),
                // The month so far, from the 1st up to and including this day.
                'month' => $calculator->profitBetween($business, $day->copy()->startOfMonth(), $day),
                'checks' => $guard->checks($business, $day, $closing),
                'canClose' => $guard->canClose($business, $day, $closing),
                'history' => $history->forClosingDay($business, $day),
            ] : null,
            'day' => $day,
            'today' => $today,
            'section' => $section,
            'entry' => $entry,
            'bills' => $bills,
            'returns' => $returns,
            'purchases' => $purchases,
            'payments' => $payments,
            'collections' => $entry?->collectionLines ?? collect(),
            'expenses' => $entry?->expenseLines ?? collect(),
            'totals' => $totals,
            'closed' => $business->isDayClosed($day),
            'seesProfit' => $request->user()->can('viewReports', $business),
            'history' => $entry !== null ? $history->forDailyEntry($entry) : collect(),
        ]);
    }

    /** Older links name a day by its entry; the day is what they meant. */
    public function show(Business $business, DailyEntry $entry): RedirectResponse
    {
        $this->authorize('view', $entry);

        return redirect()->route('businesses.daily.day', [$business, $entry->business_date->toDateString()]);
    }
}
