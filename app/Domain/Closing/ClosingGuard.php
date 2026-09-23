<?php

namespace App\Domain\Closing;

use App\Enums\DocumentStatus;
use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\DailyEntry;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * The pre-close checklist.
 *
 * Closing is a hard gate, not a nag: the value of every historical number in
 * this system comes from a human having looked at the day and agreed it was
 * right, and a lock over a known-wrong day preserves the wrongness forever.
 */
class ClosingGuard
{
    /**
     * The checklist.
     *
     * A check either blocks the close or advises against it. Everything that
     * makes a day knowably wrong blocks; things that are merely worth seeing
     * before agreeing the day — goods in without a bill yet, say — advise, and
     * are marked so the screen can say which is which. Making an ordinary
     * situation a blocker would turn the gate into a nag, and a nag gets
     * clicked past.
     *
     * @return array<int, array{ok: bool, blocking: bool, label: string, detail: string}>
     */
    public function checks(Business $business, Carbon $date, ?DailyClosing $draft = null): array
    {
        $checks = [];

        // 0. A day cannot be closed before it has begun. Closing locks it and
        //    every day before it, so closing tomorrow today would lock a day
        //    nobody has entered yet — it was possible, and it happened.
        $today = $business->today();
        $arrived = $date->toDateString() <= $today->toDateString();

        $checks[] = [
            'blocking' => true,
            'ok' => $arrived,
            'label' => 'The day has come',
            'detail' => $arrived
                ? 'This day has started, so it can be closed once its business is done.'
                : $date->format('D d M Y') . ' has not started yet — it is still ' . $today->format('D d M Y')
                    . ' here. Close it once the day is over.',
        ];

        // 1. No gaps: a break in the chain makes every later day unverifiable.
        $previous = $date->copy()->subDay();
        $firstDay = $business->opening_date !== null
            && $previous->lessThanOrEqualTo($business->opening_date);

        /*
         * The lock is the authority on what is closed, not the closing records.
         *
         * A business can be locked through a date with no closing row behind it
         * — the opening balance locks everything up to itself, and a lock moved
         * by hand leaves no row either. Requiring a finalized DailyClosing for
         * the previous day made the first day after such a lock impossible to
         * close, and therefore every day after it: the whole business wedged.
         */
        $lockedThrough = $business->locked_through_date;

        $previousClosed = $firstDay
            || ($lockedThrough !== null && $previous->lessThanOrEqualTo($lockedThrough))
            || DailyClosing::forBusiness($business)
                ->where('business_date', $previous->toDateString())
                ->finalized()
                ->exists();

        /*
         * Naming only the previous day understates it: if the lock is weeks
         * back, that day cannot close either, and so on down. Say how many are
         * outstanding and which one is actually next, so the reader is not led
         * one day at a time into the same message.
         */
        $due = $business->locked_through_date?->copy()->addDay()
            ?? $business->opening_date?->copy()->addDay()
            ?? $date;

        $outstanding = $previousClosed ? 0 : (int) $due->diffInDays($date);

        $checks[] = [
            'blocking' => true,
            'ok' => $previousClosed,
            'label' => 'Previous day closed',
            'detail' => match (true) {
                $previousClosed && $firstDay => 'This is the first day after the opening balance.',
                $previousClosed => $previous->format('d M Y').' is closed.',
                $outstanding === 1 => $due->format('d M Y').' must be closed first — days close in sequence.',
                default => $outstanding.' earlier days are still open, from '.$due->format('d M Y')
                    .' to '.$previous->format('d M Y').'. Days close in sequence, so '
                    .$due->format('d M Y').' is the one to close next.',
            },
        ];

        // 2. Nothing left in draft.
        $drafts = DailyEntry::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->where('status', DocumentStatus::Draft)
            ->count();

        $checks[] = [
            'blocking' => true,
            'ok' => $drafts === 0,
            'label' => 'No unposted entries',
            'detail' => $drafts === 0
                ? 'Everything entered for this day has been posted.'
                : "{$drafts} entry still in draft. Closing now would lock in a known-wrong day.",
        ];

        $draftTransactions = Transaction::forBusiness($business)
            ->whereDate('business_date', $date->toDateString())
            ->where('status', 'draft')
            ->count();

        $checks[] = [
            'blocking' => true,
            'ok' => $draftTransactions === 0,
            'label' => 'No draft transactions',
            'detail' => $draftTransactions === 0
                ? 'No transactions are awaiting posting.'
                : "{$draftTransactions} draft transactions on this day.",
        ];

        // 3. Cash counted — the only independent check on the largest flow in
        //    an all-cash business.
        $counted = $draft?->counted_cash !== null;

        $checks[] = [
            'blocking' => true,
            'ok' => $counted,
            'label' => 'Cash reconciled',
            'detail' => $counted
                ? 'Physical cash counted and compared to the ledger.'
                : 'Count the cash and record it before closing.',
        ];

        // 4. Any difference explained.
        $varianceExplained = ! $counted
            || ! $draft->hasCashVariance()
            || trim((string) $draft->variance_reason) !== '';

        $checks[] = [
            'blocking' => true,
            'ok' => $varianceExplained,
            'label' => 'Cash variance explained',
            'detail' => $varianceExplained
                ? 'No unexplained difference.'
                : 'An unexplained variance is a signal, not a rounding issue. Say what happened.',
        ];

        /*
         * 5. Goods in without a bill.
         *
         * A delivery recorded on this day raises the packs held straight away,
         * but only its invoice reaches the ledger — so a delivery with no bill
         * yet means stock is understated in the accounts for this day. That is
         * ordinary (the bill often follows the van), so it advises rather than
         * blocks; closing it away silently is what would be wrong.
         */
        $uninvoiced = Order::forBusiness($business)
            ->where('status', OrderStatus::Received)
            ->whereDate('received_at', $date->toDateString())
            ->whereDoesntHave('purchaseLine')
            ->with('company')
            ->get();

        $checks[] = [
            'blocking' => false,
            'ok' => $uninvoiced->isEmpty(),
            'label' => 'Deliveries invoiced',
            'detail' => $uninvoiced->isEmpty()
                ? 'Every delivery received today has its bill recorded.'
                : $uninvoiced->count().' '.str('delivery')->plural($uninvoiced->count())
                    .' received today with no bill yet ('.$uninvoiced->pluck('reference')->join(', ')
                    .'). The packs are counted, but nothing reaches the ledger until the invoice is entered.',
        ];

        return $checks;
    }

    /** Only a blocking check that has failed stops the close. */
    public function canClose(Business $business, Carbon $date, ?DailyClosing $draft = null): bool
    {
        foreach ($this->checks($business, $date, $draft) as $check) {
            if (($check['blocking'] ?? true) && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    public function blockers(Business $business, Carbon $date, ?DailyClosing $draft = null): array
    {
        return collect($this->checks($business, $date, $draft))
            ->filter(fn ($c) => $c['blocking'] ?? true)
            ->reject(fn ($c) => $c['ok'])
            ->map(fn ($c) => $c['detail'])
            ->values()
            ->all();
    }
}
