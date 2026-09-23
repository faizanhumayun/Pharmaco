<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Closing\DailyClosingCalculator;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\PostingSpec;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records the physical cash count and posts any difference.
 *
 * The cheapest valuable control in the application: it turns an invisible,
 * compounding error into a visible daily one. A business that reconciles cash
 * nightly finds a problem within a day; one that does not finds it a year later
 * as an unexplainable gap that destroys confidence in every other number.
 */
class ReconcileCash
{
    public function __construct(
        private readonly DailyClosingCalculator $calculator,
        private readonly LedgerPoster $poster,
    ) {}

    public function handle(
        Business $business,
        Carbon $date,
        Money $counted,
        ?string $reason,
        User $by,
    ): DailyClosing {
        $closing = DailyClosing::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->first();

        if ($closing?->isFinalized()) {
            throw new LedgerException('This day is closed. Reopen it before changing the cash count.');
        }

        return DB::transaction(function () use ($business, $date, $counted, $reason, $by) {
            $figures = $this->calculator->compute($business, $date);
            $variance = $counted->minus($figures['closing_cash']);

            if (! $variance->isZero() && trim((string) $reason) === '') {
                throw new LedgerException(
                    'A cash difference must be explained before it can be recorded.'
                );
            }

            /*
             * The difference posts as a real transaction rather than being
             * noted and forgotten. Cash short is an expense; cash over is
             * other income. Either way the ledger and the safe agree afterwards.
             */
            if (! $variance->isZero()) {
                $this->poster->post(new PostingSpec(
                    business: $business,
                    date: $date,
                    type: TransactionType::Adjustment,
                    lines: $variance->isNegative()
                        ? [
                            PostingLine::debit(AccountCode::OperatingExpenses, $variance->absolute(), 'Cash short'),
                            PostingLine::credit(AccountCode::Cash, $variance->absolute()),
                        ]
                        : [
                            PostingLine::debit(AccountCode::Cash, $variance),
                            PostingLine::credit(AccountCode::DiscountReceived, $variance, 'Cash over'),
                        ],
                    createdBy: $by,
                    narration: 'Cash reconciliation difference',
                    reason: $reason,
                ));

                // Recompute: the adjustment just changed the closing figures.
                $figures = $this->calculator->compute($business, $date);
            }

            $closing = DailyClosing::updateOrCreate(
                ['business_id' => $business->id, 'business_date' => $date->toDateString()],
                [
                    ...array_map(fn (Money $m) => $m->toDecimal(), $figures),
                    'status' => 'draft',
                    'counted_cash' => $counted->toDecimal(),
                    'cash_variance' => $variance->toDecimal(),
                    'variance_reason' => $reason,
                    'built_at' => now(),
                ]
            );

            // Who counted the drawer is as much a part of the close as who
            // closed it — and a later recount must not erase an earlier one.
            activity()
                ->performedOn($closing)
                ->causedBy($by)
                ->withProperties([
                    'date' => $date->toDateString(),
                    'counted' => $counted->toDecimal(),
                    'expected' => $counted->minus($variance)->toDecimal(),
                    'difference' => $variance->toDecimal(),
                    'reason' => $reason,
                ])
                ->event('closing.cash_counted')
                ->log('Cash counted');

            return $closing;
        });
    }
}
