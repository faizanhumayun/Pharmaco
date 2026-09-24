<?php

namespace App\Domain\Products\Actions;

use App\Models\CompanyProduct;
use App\Models\CompanyProductImport;
use App\Models\CompanyProductPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Writes down what a product costs today.
 *
 * Price history was written only by the price-list import, so a product that
 * reached the catalogue any other way — carried over from an old system, or
 * added when a delivery turned up with something new in it — had prices but no
 * record of where they came from. The page said "No price history" about a
 * product whose price was right there above it.
 *
 * A price is a price however it arrived, so recording one is its own action
 * and every path that sets one calls it. The import link stays optional: it
 * says which list moved the figure, when a list was what moved it.
 */
class RecordProductPrice
{
    public function handle(
        CompanyProduct $product,
        User $by,
        ?CompanyProductImport $import = null,
        ?Carbon $date = null,
        ?Model $source = null,
    ): ?CompanyProductPrice {
        // Nothing worth recording. A product with no figures on it has not
        // been priced; it has merely been named.
        if ($product->mrp === null && $product->trade_price === null && $product->purchase_rate === null) {
            return null;
        }

        $date ??= $import?->business_date ?? $product->priced_on ?? $product->business->today();

        /*
         * The same figures on the same day are one fact, not two. Re-running an
         * import, or correcting a delivery, must not leave the history looking
         * like the price moved and moved back.
         */
        $last = $product->prices()->first();

        if ($last !== null && $this->same($last, $product) && $last->business_date->isSameDay($date)) {
            return $last;
        }

        return CompanyProductPrice::create([
            'business_id' => $product->business_id,
            'company_id' => $product->company_id,
            'company_product_id' => $product->id,
            'company_product_import_id' => $import?->id,
            // What moved it, when something other than a list did.
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'business_date' => $date->toDateString(),
            'mrp' => $product->mrp,
            'trade_price' => $product->trade_price,
            'purchase_rate' => $product->purchase_rate,
            'case_size' => $product->case_size,
            'recorded_by' => $by->id,
        ]);
    }

    private function same(CompanyProductPrice $row, CompanyProduct $product): bool
    {
        return $row->mrp?->toDecimal() === $product->mrp?->toDecimal()
            && $row->trade_price?->toDecimal() === $product->trade_price?->toDecimal()
            && $row->purchase_rate?->toDecimal() === $product->purchase_rate?->toDecimal()
            && (int) $row->case_size === (int) $product->case_size;
    }
}
