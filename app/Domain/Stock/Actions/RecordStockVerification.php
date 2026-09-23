<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Ledger\BalanceService;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\PostingSpec;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\StockVerification;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordStockVerification
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly LedgerPoster $poster,
    ) {}

    public function handle(
        Business $business,
        Carbon $date,
        Money $counted,
        ?string $reason,
        User $by,
    ): StockVerification {
        if ($business->isDayClosed($date)) {
            throw new LedgerException('That day is closed. Verify stock in the open period instead.');
        }

        return DB::transaction(function () use ($business, $date, $counted, $reason, $by) {
            $book = $this->balances->asAt($business, AccountCode::Stock, $date);
            $variance = $counted->minus($book);

            $transaction = null;

            /*
             * The difference posts to profit and loss as a real cost, not as a
             * quiet correction. Expiry, breakage and theft are invisible to the
             * derived-COGS model, and this is where they finally surface.
             */
            if (! $variance->isZero()) {
                $transaction = $this->poster->post(new PostingSpec(
                    business: $business,
                    date: $date,
                    type: TransactionType::StockAdjustment,
                    lines: $variance->isNegative()
                        ? [
                            PostingLine::debit(AccountCode::StockVariance, $variance->absolute(), 'Shortfall on count'),
                            PostingLine::credit(AccountCode::Stock, $variance->absolute()),
                        ]
                        : [
                            PostingLine::debit(AccountCode::Stock, $variance),
                            PostingLine::credit(AccountCode::StockVariance, $variance, 'Surplus on count'),
                        ],
                    createdBy: $by,
                    narration: 'Physical stock verification',
                    reason: $reason,
                ));
            }

            return StockVerification::updateOrCreate(
                ['business_id' => $business->id, 'business_date' => $date->toDateString()],
                [
                    'book_value' => $book->toDecimal(),
                    'counted_value' => $counted->toDecimal(),
                    'variance' => $variance->toDecimal(),
                    'variance_pct' => $book->isZero()
                        ? 0
                        : round((float) $variance->toDecimal() / (float) $book->toDecimal() * 100, 4),
                    'reason' => $reason,
                    'transaction_id' => $transaction?->id,
                    'verified_by' => $by->id,
                ]
            );
        });
    }
}
