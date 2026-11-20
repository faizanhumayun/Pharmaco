<?php

namespace App\Domain\Stock;

use App\Models\Business;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which of the stock on the shelf is running out of time.
 *
 * Stock is a single number per product — the sum of its movements — and that
 * number cannot say which batch is left. So this works it out rather than
 * storing it: deliveries are laid out oldest expiry first, and the quantity
 * actually held is poured into them in that order.
 *
 * The assumption is that the oldest goods leave first, which is what a shop
 * does and what this whole feature exists to help it keep doing. Where it is
 * wrong it is wrong in the safe direction — it will warn about a batch that
 * has already been sold before it stays quiet about one that has not.
 *
 * Derived, never stored, for the same reason balances are: a cached "expires
 * on" column would be wrong the moment a delivery is corrected.
 */
class ExpiryWatch
{
    public function __construct(private readonly StockLedger $stock) {}

    /**
     * The soonest expiry still sitting in stock, per product.
     *
     * @return Collection<int, array{expires_on: Carbon, packs: int}>
     */
    public function soonestByProduct(Business $business): Collection
    {
        // The first batch still holding anything is the one that will go out
        // of date first, and the only one worth naming at the counter.
        return $this->heldBatchesByProduct($business)
            ->map(fn (array $batches) => $batches[0]);
    }

    /**
     * Every batch the stock on hand can still be holding, per product, oldest
     * expiry first.
     *
     * A product can have an expired batch and a fresh one on the same shelf,
     * and they are different problems: one is a loss unless it goes back to
     * the company, the other is only something to sell first.
     *
     * @return Collection<int, list<array{expires_on: Carbon, packs: int}>>
     */
    public function heldBatchesByProduct(Business $business): Collection
    {
        $onHand = $this->stock->onHandByProduct($business);

        /*
         * Anything that brought goods in is a batch: a delivery, and equally the
         * opening count, which is stock that arrived before the books began and
         * carries a date on its cartons like any other. What goes out does not —
         * a sale takes from a batch rather than making one. Oldest first, in one
         * query for the business rather than one per product.
         */
        $batches = StockMovement::forBusiness($business)
            ->whereNotNull('expires_on')
            ->where('packs', '>', 0)
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get(['company_product_id', 'expires_on', 'packs', 'batch_no'])
            ->groupBy('company_product_id');

        return $batches->mapWithKeys(function (Collection $forProduct, int $productId) use ($onHand) {
            $left = max(0, (int) ($onHand[$productId] ?? 0));
            $held = [];

            foreach ($forProduct as $batch) {
                if ($left <= 0) {
                    break;
                }

                $fromThisBatch = min($left, (int) $batch->packs);
                $left -= $fromThisBatch;

                $held[] = [
                    'expires_on' => $batch->expires_on,
                    'packs' => $fromThisBatch,
                    'batch_no' => $batch->batch_no,
                ];
            }

            return $held === [] ? [] : [$productId => $held];
        });
    }

    /**
     * How a batch should be treated: gone, going, or fine.
     *
     * Returns null when the business has asked not to be warned, or when the
     * date is far enough off to be nobody's problem yet.
     */
    public function stateFor(Business $business, ?Carbon $expiresOn): ?string
    {
        if ($expiresOn === null) {
            return null;
        }

        $today = $business->today();

        if ($expiresOn->lt($today->copy()->startOfDay())) {
            return 'expired';
        }

        $horizon = $business->expiryHorizon();

        return $horizon !== null && $expiresOn->lte($horizon) ? 'expiring' : null;
    }
}
