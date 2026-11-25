<?php

namespace App\Domain\Pos;

use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\PosBillLine;
use App\Support\Money;

/**
 * Turns what the till sent into the lines of a bill.
 *
 * Used when a bill is first rung up and again when one is corrected, so the
 * two cannot price, cost or round a line differently. Priced and costed from
 * this business's own catalogue, never from what the browser said.
 */
class BillLines
{
    /**
     * @param  array<int, array{product_id: int, quantity?: int, loose?: int, unit_price?: string}>  $items
     * @param  array<int, string>  $knownCosts  unit cost by product id, for lines that were already on the bill
     * @return array{lines: array<int, array<string, mixed>>, total: Money, cost: Money}
     */
    public function build(Business $business, array $items, array $knownCosts = []): array
    {
        if ($items === []) {
            throw new LedgerException('Add at least one item to the bill.');
        }

        $products = CompanyProduct::forBusiness($business)
            ->whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        $lines = [];
        $total = Money::zero();
        $cost = Money::zero();

        foreach ($items as $item) {
            $product = $products->get($item['product_id'] ?? 0);

            if ($product === null) {
                throw new LedgerException('One of the items is not in this business\'s product list.');
            }

            if (isset($lines[$product->id])) {
                throw new LedgerException("{$product->brand_name} is on the bill twice. Enter it on one line only.");
            }

            $size = max(1, (int) $product->units_per_pack);
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            // Loose pieces only where a pack can be opened, and never a whole
            // pack's worth: that is a pack, and is counted as one.
            $loose = $product->sellsLoose() ? max(0, (int) ($item['loose'] ?? 0)) : 0;
            $quantity += intdiv($loose, $size);
            $loose %= $size;

            if ($quantity < 1 && $loose < 1) {
                throw new LedgerException("{$product->brand_name}: enter a quantity greater than zero.");
            }

            $unitPrice = Money::of($item['unit_price'] ?? $product->sellingPriceFor($business) ?? 0);

            // Only what was paid for the goods counts as their cost. A line
            // that was already on the bill keeps the cost it was sold at.
            $unitCost = isset($knownCosts[$product->id])
                ? Money::of($knownCosts[$product->id])
                : ($product->purchase_rate ?? Money::zero());

            /*
             * Never below what it cost. A price typed at the counter is the
             * one figure on a bill that nothing else checks. Refused here
             * rather than only in the till, because a rule the browser keeps
             * is not a rule.
             */
            if ($unitCost->isPositive() && $unitPrice->lessThan($unitCost)) {
                throw new LedgerException(
                    "{$product->brand_name}: the price of Rs. {$unitPrice->format()} is less than the purchase price of Rs. {$unitCost->format()}."
                );
            }

            $lineTotal = $unitPrice->times($quantity)
                ->plus(PosBillLine::piece($unitPrice, $size)->times($loose));

            $lines[$product->id] = [
                'business_id' => $business->id,
                'company_product_id' => $product->id,
                'name' => $product->label(),
                'quantity' => $quantity,
                'loose_quantity' => $loose,
                'units_per_pack' => $size,
                'unit_price' => $unitPrice->toDecimal(),
                'unit_cost' => $unitCost->toDecimal(),
                'line_total' => $lineTotal->toDecimal(),
            ];

            $total = $total->plus($lineTotal);
            $cost = $cost->plus($unitCost->times($quantity))
                ->plus(PosBillLine::piece($unitCost, $size)->times($loose));
        }

        return ['lines' => array_values($lines), 'total' => $total, 'cost' => $cost];
    }
}
