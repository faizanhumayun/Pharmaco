<?php

namespace App\Domain\Stock;

use App\Exceptions\LedgerException;
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
    /** @var array<int, Collection<int, int>> */
    private array $perPack = [];

    /**
     * Whole packs of one product held, as at a date (or now).
     *
     * A pack that has been broken open no longer counts as a whole one; the
     * pieces left in it are {@see looseOnHand()}.
     */
    public function onHand(Business $business, CompanyProduct|int $product, ?string $asAt = null): int
    {
        $id = $product instanceof CompanyProduct ? $product->id : $product;

        return (int) ($this->onHandByProduct($business, $asAt, [$id])[$id] ?? 0);
    }

    /** Pieces left over beyond the whole packs — the opened pack on the shelf. */
    public function looseOnHand(Business $business, CompanyProduct|int $product, ?string $asAt = null): int
    {
        $id = $product instanceof CompanyProduct ? $product->id : $product;

        return (int) ($this->looseByProduct($business, $asAt, [$id])[$id] ?? 0);
    }

    /**
     * Whole packs held for every product that has ever moved, keyed by product id.
     *
     * One query rather than one per product: a catalogue of a few thousand
     * asked one at a time is how a page that should be instant becomes slow.
     *
     * @param  array<int, int>|null  $only
     * @return Collection<int, int>
     */
    public function onHandByProduct(Business $business, ?string $asAt = null, ?array $only = null): Collection
    {
        $per = $this->unitsPerPack($business);

        return $this->piecesByProduct($business, $asAt, $only)
            ->map(fn (int $pieces, int $id) => $this->wholePacks($pieces, $per[$id] ?? 1));
    }

    /**
     * Loose pieces beyond the whole packs, keyed by product id.
     *
     * @param  array<int, int>|null  $only
     * @return Collection<int, int>
     */
    public function looseByProduct(Business $business, ?string $asAt = null, ?array $only = null): Collection
    {
        $per = $this->unitsPerPack($business);

        return $this->piecesByProduct($business, $asAt, $only)
            ->map(function (int $pieces, int $id) use ($per) {
                $size = $per[$id] ?? 1;

                return $pieces - $this->wholePacks($pieces, $size) * $size;
            });
    }

    /**
     * Everything held, counted in the smallest thing sold: pieces. For a
     * product that is never broken open, a piece and a pack are the same.
     *
     * @param  array<int, int>|null  $only
     * @return Collection<int, int>
     */
    public function piecesByProduct(Business $business, ?string $asAt = null, ?array $only = null): Collection
    {
        $per = $this->unitsPerPack($business);

        return StockMovement::forBusiness($business)
            ->upTo($asAt)
            ->when($only !== null, fn ($q) => $q->whereIn('company_product_id', $only))
            ->groupBy('company_product_id')
            ->selectRaw('company_product_id, SUM(packs) as packs, SUM(loose) as loose')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->company_product_id => (int) $row->packs * ($per[(int) $row->company_product_id] ?? 1) + (int) $row->loose,
            ]);
    }

    /**
     * Stock never goes below nothing.
     *
     * Called at the end of anything that takes goods out, inside the same
     * transaction, so a refusal here undoes the whole thing. You cannot sell,
     * write off or send back what the books say is not there — put the count
     * right first, then record what happened.
     *
     * @param  array<int, int>  $productIds
     */
    public function assertNotNegative(Business $business, array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter($productIds)));

        if ($productIds === []) {
            return;
        }

        $short = $this->piecesByProduct($business, null, $productIds)->filter(fn (int $pieces) => $pieces < 0);

        if ($short->isEmpty()) {
            return;
        }

        $product = CompanyProduct::forBusiness($business)->find($short->keys()->first());
        $size = max(1, (int) ($product?->units_per_pack ?? 1));
        $missing = abs((int) $short->first());

        throw new LedgerException(sprintf(
            '%s: stock is not enough. Short by %s. Stock cannot go below zero.',
            $product?->label() ?? 'A product',
            $size > 1
                ? trim((intdiv($missing, $size) > 0 ? intdiv($missing, $size).' '.$business->unit()->many().' ' : '')
                    .($missing % $size > 0 ? ($missing % $size).' loose' : ''))
                : $missing.' '.($missing === 1 ? $business->unit()->one() : $business->unit()->many()),
        ));
    }

    /** @return Collection<int, int> pieces per pack, only where a pack can be opened */
    private function unitsPerPack(Business $business): Collection
    {
        return $this->perPack[$business->id] ??= CompanyProduct::forBusiness($business)
            ->where('units_per_pack', '>', 1)
            ->pluck('units_per_pack', 'id')
            ->map(fn ($n) => (int) $n);
    }

    private function wholePacks(int $pieces, int $size): int
    {
        // Rounds toward zero so an overdrawn count never looks deeper than it is.
        return $size <= 1 ? $pieces : intdiv($pieces, $size);
    }

    /**
     * Every movement of one product, oldest first, with a running total.
     *
     * @return Collection<int, array{movement: StockMovement, running: int}>
     */
    public function history(Business $business, CompanyProduct $product): Collection
    {
        $size = max(1, (int) $product->units_per_pack);
        $pieces = 0;

        return StockMovement::forBusiness($business)
            ->where('company_product_id', $product->id)
            ->with(['creator', 'source'])
            ->orderBy('business_date')
            ->orderBy('id')
            ->get()
            ->map(function (StockMovement $movement) use (&$pieces, $size) {
                $pieces += $movement->packs * $size + $movement->loose;
                $whole = $this->wholePacks($pieces, $size);

                return ['movement' => $movement, 'running' => $whole, 'running_loose' => $pieces - $whole * $size];
            });
    }

    /** How many distinct products have any stock at all. */
    public function productsHeld(Business $business): int
    {
        return $this->onHandByProduct($business)->filter(fn (int $packs) => $packs > 0)->count();
    }
}
