<?php

namespace App\Domain\Stock;

use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * The only class that reads quantities.
 *
 * Everything asks here, exactly as everything asks BalanceService for money.
 * A quantity is always a sum over {@see StockMovement}, computed on demand and
 * never cached on a product, so there is no second figure to disagree with the
 * movements that produced it.
 */
class StockLedger
{
    /** Packs of one product held, as at a date (or now). */
    public function onHand(Business $business, CompanyProduct|int $product, ?string $asAt = null): int
    {
        return (int) StockMovement::forBusiness($business)
            ->where('company_product_id', $product instanceof CompanyProduct ? $product->id : $product)
            ->upTo($asAt)
            ->sum('packs');
    }

    /**
     * Packs held for every product that has ever moved, keyed by product id.
     *
     * One query rather than one per product: a catalogue of a few thousand
     * asked one at a time is how a page that should be instant becomes slow.
     *
     * @return Collection<int, int>
     */
    public function onHandByProduct(Business $business, ?string $asAt = null): Collection
    {
        return StockMovement::forBusiness($business)
            ->upTo($asAt)
            ->groupBy('company_product_id')
            ->selectRaw('company_product_id, SUM(packs) as packs')
            ->pluck('packs', 'company_product_id')
            ->map(fn ($packs) => (int) $packs);
    }

    /**
     * Every movement of one product, oldest first, with a running total.
     *
     * @return Collection<int, array{movement: StockMovement, running: int}>
     */
    public function history(Business $business, CompanyProduct $product): Collection
    {
        $running = 0;

        return StockMovement::forBusiness($business)
            ->where('company_product_id', $product->id)
            ->with(['creator', 'source'])
            ->orderBy('business_date')
            ->orderBy('id')
            ->get()
            ->map(function (StockMovement $movement) use (&$running) {
                $running += $movement->packs;

                return ['movement' => $movement, 'running' => $running];
            });
    }

    /** How many distinct products have any stock at all. */
    public function productsHeld(Business $business): int
    {
        return $this->onHandByProduct($business)->filter(fn (int $packs) => $packs > 0)->count();
    }
}
