<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\PostingSpec;
use App\Enums\AccountCode;
use App\Enums\StockMovementType;
use App\Enums\TransactionType;
use App\Models\PosBillLine;
use App\Support\Money;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What the owner decided a difference meant.
 *
 * Approving it moves the stock figure to what was found, through the one class
 * that writes movements — the count does not get its own back door into the
 * quantity ledger. Dismissing it says the shelf was miscounted and leaves the
 * books alone. Either way the count stays on the record: a difference that was
 * judged wrong is still worth having found.
 */
class ResolveStockCount
{
    public function __construct(
        private readonly RecordStockMovements $movements,
        private readonly LedgerPoster $poster,
    ) {}

    /**
     * @param  bool  $post  false when the caller books the value itself — a
     *                      company audit posts one transaction for the rack
     *                      rather than one per product.
     */
    public function approve(StockCount $count, User $by, ?string $reason = null, bool $post = true): StockCount
    {
        if (! $count->isOpen()) {
            throw new RuntimeException('This count has already been resolved.');
        }

        return DB::transaction(function () use ($count, $by, $reason, $post) {
            // In pieces, then said as packs and loose pieces of the same
            // sign — which is how the quantity ledger adds them up.
            $per = max(1, (int) $count->product->units_per_pack);
            $pieces = $count->difference();
            $difference = intdiv($pieces, $per);
            $loose = $pieces % $per;

            $found = $pieces > 0
                ? $count->lines
                    ->filter(fn ($l) => $l->expires_on !== null && $l->difference($per) > 0)
                    ->sortByDesc(fn ($l) => $l->difference($per))
                    ->first()
                : null;

            // Undated stock found to be a real batch goes onto that batch first.
            $this->redate($count, $by);

            // The adjustment carries the count's own words about what happened,
            // so the movement explains itself on the product's page.
            $movement = $this->movements->adjust(
                $count->business,
                $count->product,
                $difference,
                StockMovementType::Adjustment,
                trim(sprintf(
                    'Stock count %s — system %s, counted %s. %s',
                    $count->business_date->format('j M Y'),
                    $count->expectedLabel(),
                    $count->countedLabel(),
                    $reason ?? $count->note ?? '',
                )),
                $by,
                loose: $loose,
                // Stock found on the shelf came in with a date like any other,
                // so it goes on the books under the batch it was found as —
                // the one that grew most — and the expiry watch can see it.
                expiresOn: $found?->expires_on?->toDateString(),
                batchNo: $found?->batch_no,
            );

            /*
             * The value follows the quantity into the books, at cost: stock
             * found short is a loss (Stock Variance, which comes off net
             * profit), stock found over adds to stock and back to profit.
             * Synchronous, in this transaction: a count approved without its
             * value would leave the Stock account disagreeing with the shelf.
             */
            if ($post) {
                $this->book($count, $by, $reason);
            }

            $count->forceFill([
                'status' => StockCount::ADJUSTED,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'stock_movement_id' => $movement->id,
            ])->save();

            return $count->fresh();
        });
    }

    public function dismiss(StockCount $count, User $by, ?string $reason = null): StockCount
    {
        if (! $count->isOpen()) {
            throw new RuntimeException('This count has already been resolved.');
        }

        $count->forceFill([
            'status' => StockCount::DISMISSED,
            'approved_by' => $by->id,
            'approved_at' => now(),
            'note' => trim(($count->note ? $count->note.' — ' : '').($reason ?? 'Marked as a counting mistake')),
        ])->save();

        return $count->fresh();
    }

    /** What a count's difference is worth at the product's cost, signed. */
    public function valueOf(StockCount $count): Money
    {
        $product = $count->product;
        $per = max(1, (int) $product->units_per_pack);
        $cost = $product->purchase_rate ?? $product->trade_price ?? Money::zero();

        // Batch by batch at each batch's own cost, where the count has them.
        return app(\App\Domain\Stock\Audits\CompanyAuditReport::class)
            ->priced($count->loadMissing('lines'), 'unit_cost', $cost, $per, 'diff');
    }

    private function book(StockCount $count, User $by, ?string $reason): void
    {
        $value = $this->valueOf($count);

        if ($value->isZero()) {
            return;
        }

        $this->poster->post(new PostingSpec(
            business: $count->business,
            date: $count->business->today(),
            type: TransactionType::StockAdjustment,
            lines: $value->isNegative()
                ? [
                    PostingLine::debit(AccountCode::StockVariance, $value->absolute(), 'Short on count'),
                    PostingLine::credit(AccountCode::Stock, $value->absolute()),
                ]
                : [
                    PostingLine::debit(AccountCode::Stock, $value),
                    PostingLine::credit(AccountCode::StockVariance, $value, 'Over on count'),
                ],
            createdBy: $by,
            narration: sprintf('Stock count — %s: %s', $count->product->label(), $count->differenceLabel()),
            source: $count,
            reason: $reason ?? $count->note,
        ));
    }

    /**
     * Stock on the books without a date, found on the shelf under a batch.
     *
     * Stock entered before dates were kept has no expiry, so nothing warns
     * about it. When a count finds it under a real batch, that quantity moves
     * from undated to the batch: out of one, into the other, the same amount.
     * The total and the value are unchanged, so nothing is posted to the
     * ledger — only the expiry watch learns what it is holding.
     */
    public function redate(StockCount $count, User $by): void
    {
        $count->loadMissing('lines', 'product');
        $per = max(1, (int) $count->product->units_per_pack);

        $undated = $count->lines->first(fn ($l) => ! $l->is_new && $l->batch_no === null && $l->expires_on === null);
        $shrunk = $undated ? max(0, -$undated->difference($per)) : 0;

        $found = $count->lines->filter(fn ($l) => $l->is_new && $l->expires_on !== null
            && $l->counted_packs * $per + $l->counted_loose > 0);

        $moved = min($shrunk, (int) $found->sum(fn ($l) => $l->counted_packs * $per + $l->counted_loose));

        if ($moved <= 0) {
            return;
        }

        $note = sprintf('Dated on stock count %s', $count->business_date->format('j M Y'));

        // Out of the undated stock…
        $this->movements->adjust($count->business, $count->product, -intdiv($moved, $per),
            StockMovementType::Adjustment, $note, $by, loose: -($moved % $per));

        // …and into each batch it was found as, in turn.
        $left = $moved;
        foreach ($found as $line) {
            $take = min($left, $line->counted_packs * $per + $line->counted_loose);
            $left -= $take;

            $this->movements->adjust($count->business, $count->product, intdiv($take, $per),
                StockMovementType::Adjustment, $note, $by, loose: $take % $per,
                expiresOn: $line->expires_on->toDateString(), batchNo: $line->batch_no);

            if ($left === 0) {
                break;
            }
        }
    }
}
