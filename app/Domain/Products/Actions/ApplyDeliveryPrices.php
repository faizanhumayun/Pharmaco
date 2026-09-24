<?php

namespace App\Domain\Products\Actions;

use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The prices a delivery told us about.
 *
 * A delivery is the moment the three figures are known together: the invoice
 * says what the goods cost, the pack says what is printed on it, and the owner
 * decides what to sell it for. Catching them here is the difference between a
 * catalogue that stays current and one that is right on the day it was
 * imported and drifts from then on.
 *
 * Each is left alone when nothing was entered for it — a blank box means "no
 * change", never "set it to nothing".
 */
class ApplyDeliveryPrices
{
    /*
     * Deliberately does not write history itself. A delivery settles the cost
     * and the selling prices in separate steps, and a row per step would read
     * as two price changes an instant apart. Receiving writes the one row,
     * once every figure is on the product — and history rows are immutable, so
     * there is no correcting it afterwards.
     */

    /**
     * @param  array<int, array{mrp?: string|null, trade?: string|null}>  $byProduct  keyed by product id
     */
    public function handle(
        Business $business,
        array $byProduct,
        User $by,
        ?Carbon $date = null,
    ): int {
        $moved = 0;

        $products = CompanyProduct::forBusiness($business)
            ->whereIn('id', array_keys($byProduct))
            ->get()
            ->keyBy('id');

        foreach ($byProduct as $id => $figures) {
            $product = $products->get($id);

            if ($product === null) {
                continue;
            }

            $changes = [];

            foreach (['mrp' => 'mrp', 'trade' => 'trade_price'] as $key => $column) {
                $entered = trim((string) ($figures[$key] ?? ''));

                if ($entered === '') {
                    continue;
                }

                $value = Money::of($entered);

                // Writing the same figure again is not a price change, and a
                // history full of unchanged rows hides the real ones.
                if ($product->{$column}?->equals($value)) {
                    continue;
                }

                $changes[$column] = $value->toDecimal();
            }

            if ($changes === []) {
                continue;
            }

            $product->forceFill($changes + ['priced_on' => ($date ?? $business->today())->toDateString()])->save();
            $moved++;
        }

        return $moved;
    }
}
