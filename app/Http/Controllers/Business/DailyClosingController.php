<?php

namespace App\Http\Controllers\Business;

use App\Domain\Closing\Actions\FinalizeDailyClosing;
use App\Domain\Closing\Actions\ReconcileCash;
use App\Domain\Closing\Actions\ReopenDailyClosing;
use App\Domain\Closing\ClosingGuard;
use App\Domain\Closing\DailyClosingCalculator;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Support\Money;
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

    /** The closings are listed in the Daily Log now, beside each day's trading. */
    public function index(Request $request, Business $business): RedirectResponse
    {
        $this->authorize('viewAny', [DailyClosing::class, $business]);

        return redirect()->route('businesses.daily.index', [$business, ...$request->only('from', 'to')]);
    }

    /**
     * A day's closing is part of its Daily Log now: Business standing shows
     * where the business stands, and Close day there counts and closes.
     * Older links land on that section. With no day named, the one due.
     */
    public function show(Business $business, ?string $date = null): RedirectResponse
    {
        $this->authorize('viewAny', [DailyClosing::class, $business]);

        $date = $date
            ? Carbon::parse($date)
            : ($business->locked_through_date?->copy()->addDay() ?? $business->opening_date?->copy()->addDay() ?? $business->today());

        return redirect()->route('businesses.daily.day', [$business, $date->toDateString(), 'section' => 'standing']);
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
                // The form counts today's drawer only, not all the owner's cash.
                drawerOnly: true,
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['variance_reason' => $e->getMessage()]);
        }

        // Back to the day, with the Close day window open again.
        return back()->with('status', 'Cash count saved.')->with('closeDay', true);
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
            ->route('businesses.daily.day', [$business, $date, 'section' => 'standing'])
            ->with('status', "Day closed. No entry can now be posted on or before {$date}.");
    }

    public function reopen(Request $request, Business $business, string $date, ReopenDailyClosing $action): RedirectResponse
    {
        $this->authorize('reopen', [DailyClosing::class, $business]);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Enter the reason for reopening this day. It is kept on the permanent record.',
        ]);

        try {
            $action->handle($business, Carbon::parse($date), $validated['reason'], $request->user());
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Day reopened. The earlier closing is kept on record.');
    }
}
