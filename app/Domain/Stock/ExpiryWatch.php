<?php

namespace App\Domain\Stock;

use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\PosBillLine;
use App\Models\StockMovement;
use App\Support\Money;
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
     * @return Collection<int, array{expires_on: Carbon, packs: int, loose: int, batch_no: ?string}>
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
     * Counted in pieces, the smallest thing sold, and handed back as whole
     * packs plus what is loose: a pharmacy's last strip of an expiring batch
     * is a few tablets, not a pack, and it still has to be found.
     *
     * @param  array<int, int>|null  $only  product ids, to look at just those
     * @return Collection<int, list<array{expires_on: Carbon, packs: int, loose: int, batch_no: ?string}>>
     */
    public function heldBatchesByProduct(Business $business, ?array $only = null): Collection
    {
        $pieces = $this->stock->piecesByProduct($business, null, $only);

        $perPack = CompanyProduct::forBusiness($business)
            ->where('units_per_pack', '>', 1)
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))
            ->pluck('units_per_pack', 'id');

        /*
         * Anything that brought goods in is a batch: a delivery, and equally the
         * opening count, which is stock that arrived before the books began and
         * carries a date on its cartons like any other. What goes out does not —
         * a sale takes from a batch rather than making one. Oldest first, in one
         * query for the business rather than one per product.
         */
        $batches = StockMovement::forBusiness($business)
            ->whereNotNull('expires_on')
            ->where(fn ($q) => $q->where('packs', '>', 0)->orWhere('loose', '>', 0))
            ->when($only !== null, fn ($q) => $q->whereIn('company_product_id', $only))
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get(['company_product_id', 'expires_on', 'packs', 'loose', 'batch_no', 'unit_cost'])
            ->groupBy('company_product_id');

        return $batches->mapWithKeys(function (Collection $forProduct, int $productId) use ($pieces, $perPack) {
            $size = max(1, (int) ($perPack[$productId] ?? 1));
            $left = max(0, (int) ($pieces[$productId] ?? 0));
            $held = [];
            $heldPieces = [];

            foreach ($forProduct as $batch) {
                if ($left <= 0) {
                    break;
                }

                $arrived = max(0, (int) $batch->packs) * $size + max(0, (int) $batch->loose);
                $fromThisBatch = min($left, $arrived);
                $left -= $fromThisBatch;

                if ($fromThisBatch === 0) {
                    continue;
                }

                // One batch that came in more than once (two deliveries, or a
                // count that dated some of it) is still one batch on the shelf.
                $key = mb_strtolower((string) $batch->batch_no).'|'.$batch->expires_on->toDateString();
                $pieces = ($heldPieces[$key] ?? 0) + $fromThisBatch;
                $heldPieces[$key] = $pieces;

                $held[$key] = [
                    'expires_on' => $batch->expires_on,
                    'packs' => intdiv($pieces, $size),
                    'loose' => $pieces % $size,
                    'batch_no' => $batch->batch_no,
                    // What this batch was bought at: its delivery's rate, the
                    // latest where it came in more than once.
                    'unit_cost' => $batch->unit_cost ?? ($held[$key]['unit_cost'] ?? null),
                ];
            }

            $held = array_values($held);

            return $held === [] ? [] : [$productId => $held];
        });
    }

    /**
     * What a held batch is worth at cost: its whole packs, plus its loose
     * pieces at the pack's cost shared out among them.
     *
     * @param  array{packs: int, loose?: int}  $batch
     */
    public function valueAtCost(CompanyProduct $product, array $batch): Money
    {
        $cost = $product->purchase_rate ?? Money::zero();

        return $cost->times($batch['packs'])
            ->plus(PosBillLine::piece($cost, max(1, (int) $product->units_per_pack))->times($batch['loose'] ?? 0));
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
