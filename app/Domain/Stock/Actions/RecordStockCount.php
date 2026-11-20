<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Stock\StockLedger;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes down what was on the shelf.
 *
 * Changes no stock. A count is somebody saying what they saw, and saying it is
 * not the same as the books agreeing — a miscount, a pack in the wrong place or
 * a sale rung up moments earlier all produce a difference that should be looked
 * at rather than posted.
 *
 * The expected figure is taken here rather than trusted from the form, so a
 * sheet left open on a screen for an hour cannot record a disagreement with a
 * quantity that has since moved.
 */
class RecordStockCount
{
    public function __construct(private readonly StockLedger $stock) {}

    /**
     * @param  int|null  $counted  null means "whatever the books say" — the
     *                            shelf was checked and agreed, and the figure
     *                            is read here rather than sent from a page that
     *                            may have been open for an hour.
     */
    public function handle(
        Business $business,
        CompanyProduct $product,
        ?int $counted,
        User $by,
        ?string $note = null,
        int $countedLoose = 0,
    ): StockCount {
        if ($product->business_id !== $business->id) {
            throw new RuntimeException('That product belongs to another business.');
        }

        if ($counted !== null && ($counted < 0 || $countedLoose < 0)) {
            throw new RuntimeException('A shelf cannot hold less than nothing.');
        }

        return DB::transaction(function () use ($business, $product, $counted, $countedLoose, $by, $note) {
            $expected = $this->stock->onHand($business, $product);
            $expectedLoose = $this->stock->looseOnHand($business, $product);
            $per = max(1, (int) $product->units_per_pack);

            if ($counted === null) {
                // The shelf agreed: both figures are the books' own.
                $counted = $expected;
                $countedLoose = $expectedLoose;
            } else {
                // Loose pieces that make up whole packs are counted as packs,
                // and a product sold only whole has no loose pieces at all.
                $pieces = $counted * $per + ($per > 1 ? $countedLoose : 0);
                $counted = intdiv($pieces, $per);
                $countedLoose = $pieces % $per;
            }

            $agrees = $counted === $expected && $countedLoose === $expectedLoose;

            return StockCount::create([
                'business_id' => $business->id,
                'company_product_id' => $product->id,
                'business_date' => $business->today()->toDateString(),
                'expected_packs' => $expected,
                'expected_loose' => $expectedLoose,
                'counted_packs' => $counted,
                'counted_loose' => $countedLoose,
                // A shelf that matches needs nobody's approval; only a
                // disagreement is a finding.
                'status' => $agrees ? StockCount::AGREED : StockCount::FOUND,
                'note' => $note,
                'counted_by' => $by->id,
                'approved_at' => $agrees ? now() : null,
                'approved_by' => $agrees ? $by->id : null,
            ]);
        });
    }
}
