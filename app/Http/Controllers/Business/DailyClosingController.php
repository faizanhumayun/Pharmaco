<?php

namespace App\Http\Controllers\Business;

use App\Domain\Reporting\ChangeHistory;
use App\Domain\Closing\Actions\FinalizeDailyClosing;
use App\Domain\Closing\Actions\ReconcileCash;
use App\Domain\Closing\Actions\ReopenDailyClosing;
use App\Domain\Closing\ClosingGuard;
use App\Domain\Closing\DailyClosingCalculator;
use App\Enums\OrderStatus;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\Order;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class DailyClosingController extends Controller
{
    public function __construct(
        private readonly DailyClosingCalculator $calculator,
        private readonly ClosingGuard $guard,
    ) {}

    public function index(Business $business): View
    {
        $this->authorize('viewAny', [DailyClosing::class, $business]);

        return view('business.closing.index', [
            'business' => $business,
            'closings' => DailyClosing::forBusiness($business)
                ->with('finalizer')
                ->orderByDesc('business_date')
                ->paginate(30),
        ]);
    }

    public function show(Request $request, Business $business, ChangeHistory $history, ?string $date = null): View
    {
        $this->authorize('viewAny', [DailyClosing::class, $business]);

        $date = $date
            ? Carbon::parse($date)
            : ($business->locked_through_date?->copy()->addDay() ?? $business->opening_date?->copy()->addDay() ?? $business->today());

        $closing = DailyClosing::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->first();

        // What physically arrived that day. Not a figure the closing stores —
        // it is derived, and it is here so the day can be read whole.
        $deliveries = Order::forBusiness($business)
            ->where('status', OrderStatus::Received)
            ->whereDate('received_at', $date->toDateString())
            ->with(['company', 'receiptLines', 'purchaseLine.dailyEntry'])
            ->orderBy('received_at')
            ->get();

        $packsIn = (int) StockMovement::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->sum('packs');

        return view('business.closing.show', [
            'business' => $business,
            'date' => $date,
            'closing' => $closing,
            'figures' => $this->calculator->compute($business, $date),
            'openingCashCorrection' => $this->calculator->openingCashCorrection($business, $date),
            'checks' => $this->guard->checks($business, $date, $closing),
            'canClose' => $this->guard->canClose($business, $date, $closing),
            'deliveries' => $deliveries,
            'deliveriesValue' => Money::sum($deliveries->map(fn (Order $o) => $o->receivedTotal())),
            'packsIn' => $packsIn,
            // Who counted, closed, reopened and corrected this day, and why.
            'history' => $history->forClosingDay($business, $date),
        ]);
    }

    public function reconcile(Request $request, Business $business, string $date, ReconcileCash $action): RedirectResponse
    {
        $this->authorize('reconcile', [DailyClosing::class, $business]);

        $validated = $request->validate([
            // May be below zero: the owner treats a drawer that paid out more
            // than it held as negative — money owed back to the drawer.
            'counted_cash' => ['required', 'string', 'regex:/^-?[\d,]*\.?\d*$/'],
            'variance_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $action->handle(
                $business,
                Carbon::parse($date),
                Money::of(str_replace(',', '', $validated['counted_cash'])),
                $validated['variance_reason'] ?? null,
                $request->user(),
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['variance_reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Cash reconciled.');
    }

    public function finalize(Request $request, Business $business, string $date, FinalizeDailyClosing $action): RedirectResponse
    {
        $this->authorize('finalize', [DailyClosing::class, $business]);

        try {
            $action->handle($business, Carbon::parse($date), $request->user());
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.closing.index', $business)
            ->with('status', "Day closed. Nothing can now post on or before {$date}.");
    }

    public function reopen(Request $request, Business $business, string $date, ReopenDailyClosing $action): RedirectResponse
    {
        $this->authorize('reopen', [DailyClosing::class, $business]);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Say why this day is being reopened. It goes on the permanent record.',
        ]);

        try {
            $action->handle($business, Carbon::parse($date), $validated['reason'], $request->user());
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Day reopened. The superseded closing is kept for the record.');
    }
}
