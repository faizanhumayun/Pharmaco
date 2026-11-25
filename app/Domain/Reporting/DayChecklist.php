<?php

namespace App\Domain\Reporting;

use App\Domain\Market\Outstanding;
use App\Enums\StockMovementType;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\DailyEntry;
use App\Models\PosBill;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * What is left to do before today is finished.
 *
 * A day in this system is not a date that passes on its own — it is a document
 * that somebody enters, posts and agrees. Until that happens the figures for
 * the day exist only as loose bills and movements, and every report that quotes
 * them is quoting a day nobody has stood behind yet.
 *
 * So the steps here are the real sequence of a trading day, and each one's state
 * is read from the records themselves rather than from a checkbox somebody
 * ticked. There is deliberately nothing to tick: a step is done when the thing
 * it describes exists in the books, which means this cannot say a day is
 * complete when it is not.
 *
 * Steps that do not apply are left out rather than shown as done — a distributor
 * that never rings up a bill is not "finished" with the counter, it simply has
 * no counter, and pretending otherwise would flatter the progress figure.
 */
class DayChecklist
{
    /** A step somebody still has to do. */
    public const TODO = 'todo';

    /** Done — the record it describes exists. */
    public const DONE = 'done';

    /** Cannot be done yet, because an earlier step has not been. */
    public const BLOCKED = 'blocked';

    /** Nothing to do, and nothing wrong with that — no expenses, no delivery. */
    public const NONE = 'none';

    public function __construct(
        private readonly Outstanding $outstanding,
        private readonly AlertEngine $alerts,
    ) {}

    /**
     * The day's steps in the order they happen, with where each one stands.
     *
     * @return array{
     *     date: Carbon,
     *     steps: list<array<string, mixed>>,
     *     done: int,
     *     outstanding: int,
     *     complete: bool,
     * }
     */
    public function for(Business $business, User $user): array
    {
        $today = $business->today();

        /*
         * Almost everything the day is made of hangs off the day's own entry —
         * its sales, expenses, collections and purchases are its lines. One
         * query with counts, rather than a query per step.
         */
        $entry = DailyEntry::forBusiness($business)
            ->whereDate('business_date', $today->toDateString())
            ->withCount(['saleLines', 'expenseLines', 'collectionLines', 'purchaseLines'])
            ->first();

        $closing = DailyClosing::forBusiness($business)
            ->whereDate('business_date', $today->toDateString())
            ->first();

        $posted = $entry !== null && ! $entry->status->isEditable();

        $steps = array_values(array_filter([
            $this->deliveries($business, $user, $today, $entry),
            $this->counter($business, $user, $today),
            $this->collections($business, $user, $entry),
            $this->expenses($business, $user, $entry),
            $this->stockCount($business, $user, $today),
            $this->approvals($business, $user),
            $this->entry($business, $user, $entry, $posted),
            $this->closing($business, $user, $closing, $posted),
            $this->earlierDays($business, $user),
        ]));

        /*
         * Only steps that are somebody's job count towards the progress figure.
         * A day with no delivery is not 90% finished for want of one.
         */
        $counted = array_filter($steps, fn (array $s) => $s['state'] !== self::NONE);
        $done = array_filter($counted, fn (array $s) => $s['state'] === self::DONE);

        return [
            'date' => $today,
            'steps' => $steps,
            'done' => count($done),
            'outstanding' => count($counted) - count($done),
            'complete' => $counted !== [] && count($done) === count($counted),
        ];
    }

    /**
     * Goods taken in. Not every day has a delivery, so an empty one is NONE
     * rather than an outstanding job.
     */
    private function deliveries(Business $business, User $user, Carbon $today, ?DailyEntry $entry): ?array
    {
        if (! $user->can('viewOrders', $business)) {
            return null;
        }

        $movements = StockMovement::forBusiness($business)
            ->where('type', StockMovementType::Delivery)
            ->whereDate('business_date', $today->toDateString())
            ->count();

        return [
            'key' => 'deliveries',
            'title' => 'Receive deliveries',
            'state' => $movements > 0 ? self::DONE : self::NONE,
            'detail' => $movements > 0
                ? "{$movements} " . str('line')->plural($movements) . ' added to stock today.'
                : 'No delivery received today. Stock can be received against an order form or without one.',
            'why' => 'Stock must be recorded before it is sold. Otherwise the stock in the system will '
                . 'not match the actual stock.',
            'route' => $user->can('manageOrders', $business) ? 'businesses.stock.receive' : 'businesses.orders.index',
            'action' => 'Add stock',
        ];
    }

    /**
     * The counter. Left out entirely for a business that does not use one —
     * its sales arrive as figures on the day entry instead.
     */
    private function counter(Business $business, User $user, Carbon $today): ?array
    {
        if (! $user->can('sellAtPos', $business)) {
            return null;
        }

        // Ever billed, not billed today: a business that has never used the
        // counter is not behind on it, but one that used it yesterday and not
        // today has something missing.
        if (! PosBill::forBusiness($business)->exists()) {
            return null;
        }

        $bills = PosBill::forBusiness($business)
            ->whereDate('business_date', $today->toDateString())
            ->get(['id', 'total']);

        $total = Money::sum($bills->pluck('total'));

        return [
            'key' => 'counter',
            'title' => "Record the day's sales",
            'state' => $bills->isNotEmpty() ? self::DONE : self::TODO,
            'detail' => $bills->isNotEmpty()
                ? $bills->count() . ' ' . str('bill')->plural($bills->count()) . ' — ' . $total->format() . '.'
                : 'No bill has been created today.',
            'why' => 'Every bill is added to the day entry automatically, so the day entry and the POS always match.',
            'route' => 'businesses.pos.index',
            'action' => 'Open POS',
            'new_tab' => true,
        ];
    }

    /** Cash the delivery staff brought back, against bills already out. */
    private function collections(Business $business, User $user, ?DailyEntry $entry): ?array
    {
        if (! $user->can('viewAny', [DailyEntry::class, $business])) {
            return null;
        }

        $recorded = $entry?->collection_lines_count ?? 0;

        // What is still out there, so the step says what it is for rather than
        // only whether it was done.
        $collected = $this->outstanding->collectedByBill($business);

        $open = $this->outstanding->bills($business)
            ->each(fn (PosBill $bill) => $bill->setAttribute('collected', $collected[$bill->id] ?? Money::zero()))
            ->filter(fn (PosBill $bill) => $this->outstanding->onBill($bill)->isPositive());

        $owed = Money::sum($open->map(fn (PosBill $bill) => $this->outstanding->onBill($bill)));

        return [
            'key' => 'collections',
            'title' => 'Record collections',
            'state' => $recorded > 0 ? self::DONE : ($open->isNotEmpty() ? self::TODO : self::NONE),
            'detail' => $recorded > 0
                ? "{$recorded} " . str('collection')->plural($recorded) . ' recorded today. '
                    . $open->count() . ' ' . str('bill')->plural($open->count()) . ' still pending — ' . $owed->format() . '.'
                : ($open->isNotEmpty()
                    ? $open->count() . ' ' . str('bill')->plural($open->count()) . ' still unpaid — ' . $owed->format()
                        . ', across ' . $open->pluck('customer_name')->filter()->unique()->count() . ' customers.'
                    : 'No amount is pending. Every bill has been paid.'),
            'why' => 'If collected cash is not recorded, the cash count will not match and the customer '
                . 'will still show a pending amount.',
            'route' => 'businesses.collections.index',
            'action' => 'Record a collection',
        ];
    }

    /** What was spent. A day with no expense is ordinary, not incomplete. */
    private function expenses(Business $business, User $user, ?DailyEntry $entry): ?array
    {
        if (! $user->can('configure', $business)) {
            return null;
        }

        $lines = $entry?->expense_lines_count ?? 0;
        $cash = $entry?->expenses_cash ?? Money::zero();

        return [
            'key' => 'expenses',
            'title' => "Record the day's expenses",
            'state' => $lines > 0 || $cash->isPositive() ? self::DONE : self::NONE,
            'detail' => $lines > 0 || $cash->isPositive()
                ? $cash->format() . ' recorded' . ($lines > 0 ? " across {$lines} " . str('line')->plural($lines) : '') . '.'
                : 'No expense recorded. Record every amount paid from the cash drawer before the cash is counted.',
            'why' => 'If an expense is not recorded, the cash will be short with no explanation.',
            'route' => 'businesses.expenses.index',
            'action' => 'Add an expense',
        ];
    }

    /** Today's counting sheet. */
    private function stockCount(Business $business, User $user, Carbon $today): ?array
    {
        if (! $user->can('verifyStock', $business)) {
            return null;
        }

        $counted = StockCount::forBusiness($business)
            ->whereDate('business_date', $today->toDateString())
            ->count();

        return [
            'key' => 'count',
            'title' => "Complete today's stock count",
            'state' => $counted > 0 ? self::DONE : self::TODO,
            'detail' => $counted > 0
                ? "{$counted} " . str('product')->plural($counted) . ' counted today.'
                : 'No product counted today. The count sheet selects the products to check by value and movement.',
            'why' => 'Stock is the only balance that nothing else checks. Counting a few products every day '
                . 'keeps the stock figure correct.',
            'route' => 'businesses.stock.audit',
            'action' => 'Open the count sheet',
        ];
    }

    /**
     * Differences somebody found but nobody has agreed to yet. Only a step when
     * there are some — an empty approvals queue is not a job.
     */
    private function approvals(Business $business, User $user): ?array
    {
        if (! $user->can('configure', $business)) {
            return null;
        }

        $open = StockCount::forBusiness($business)->where('status', StockCount::FOUND)->count();

        if ($open === 0) {
            return null;
        }

        return [
            'key' => 'approvals',
            'title' => 'Approve stock differences',
            'state' => self::TODO,
            'detail' => "{$open} " . str('count')->plural($open) . ' found a difference and '
                . ($open === 1 ? 'is' : 'are') . ' waiting for approval.',
            'why' => 'A difference changes the recorded stock, so the owner must approve it, '
                . 'not the person who counted.',
            'route' => 'businesses.stock.audit',
            'action' => 'Review differences',
        ];
    }

    /** The day itself: entered, and posted. */
    private function entry(Business $business, User $user, ?DailyEntry $entry, bool $posted): ?array
    {
        if (! $user->can('viewAny', [DailyEntry::class, $business])) {
            return null;
        }

        return [
            'key' => 'entry',
            'title' => 'Enter and post the day',
            'state' => $posted ? self::DONE : self::TODO,
            'detail' => match (true) {
                $posted => 'Posted. The day is in the ledger.',
                $entry !== null => 'Saved as a draft. A draft is not in the ledger yet. '
                    . 'Post it to include it.',
                default => 'Not entered yet. Bills created at the POS are waiting for the day entry.',
            },
            'why' => 'Posting adds the day to the ledger. Until the day is posted, no report includes it.',
            'route' => $entry !== null ? 'businesses.daily.index' : 'businesses.daily.create',
            'action' => $posted ? 'View the day' : ($entry !== null ? 'Post it' : 'Enter the day'),
        ];
    }

    /** Counting the drawer and agreeing the figure. */
    private function closing(Business $business, User $user, ?DailyClosing $closing, bool $posted): ?array
    {
        if (! $user->can('viewAny', [DailyClosing::class, $business])) {
            return null;
        }

        $finalized = $closing !== null && $closing->isFinalized();

        return [
            'key' => 'closing',
            'title' => 'Close the day',
            'state' => match (true) {
                $finalized => self::DONE,
                // Closing compares the drawer against what the day says should
                // be in it, so there has to be a posted day to compare with.
                ! $posted => self::BLOCKED,
                default => self::TODO,
            },
            'detail' => match (true) {
                $finalized => 'Closed and confirmed'
                    . ($closing->hasCashVariance() ? ' — with a cash difference recorded.' : '.'),
                ! $posted => 'Waiting for the day to be posted. The cash cannot be checked until then.',
                default => 'The day is posted. Count the cash and compare it with the day\'s figure.',
            },
            'why' => 'A figure is reliable only after someone has counted and confirmed it. Closing the day is that check.',
            'route' => 'businesses.closing.show',
            'action' => 'Close the day',
        ];
    }

    /** Days before today that were never closed. Only a step when there are some. */
    private function earlierDays(Business $business, User $user): ?array
    {
        if (! $user->can('viewAny', [DailyClosing::class, $business])) {
            return null;
        }

        $open = $this->alerts->unclosedDays($business);

        if ($open === 0) {
            return null;
        }

        return [
            'key' => 'earlier',
            'title' => $open === 1 ? 'An earlier day is still open' : "{$open} earlier days are still open",
            'state' => self::TODO,
            'detail' => 'Posted but not closed. They are in the ledger, but no one has confirmed the cash.',
            'why' => 'Past figures are reliable only after someone has confirmed they are correct.',
            'route' => 'businesses.closing.index',
            'action' => 'Close these days',
        ];
    }
}
