<?php

namespace App\Domain\Stock\Actions;

use App\Enums\StockMovementType;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only class that writes stock movements.
 *
 * Everything that changes a quantity comes through here, so the invariant that
 * a quantity is the sum of its movements is guaranteed by there being one way
 * to add to them — the same reason LedgerPoster is alone in writing entries.
 */
class RecordStockMovements
{
    /**
     * Writes the movements for a delivery, replacing any it already had.
     *
     * Goods arriving is a fact about the godown, not about money, so this does
     * not wait for the invoice to post. A delivery recorded against a day that
     * is still a draft has still physically arrived, and the count says so.
     *
     * Rewritten wholesale rather than merged, exactly as the receipt lines it
     * reads are, so a corrected delivery cannot leave a stale movement behind.
     */
    public function forDelivery(Order $order, User $by): int
    {
        return DB::transaction(function () use ($order, $by) {
            // What the delivery held before it is rewritten, so a quantity
            // corrected downwards is checked against what has since been sold.
            $before = StockMovement::forBusiness($order->business_id)
                ->where('source_type', Order::class)
                ->where('source_id', $order->id)
                ->pluck('company_product_id')
                ->all();

            $this->clearDelivery($order);

            $date = ($order->received_at ?? $order->business_date)->toDateString();
            $written = 0;

            foreach ($order->receiptLines()->get() as $line) {
                // A line typed by hand names no catalogue product, so there is
                // nothing to count it against. It still appears on the delivery.
                if ($line->company_product_id === null || ($line->packs < 1 && (int) $line->loose < 1)) {
                    continue;
                }

                StockMovement::create([
                    'business_id' => $order->business_id,
                    'company_product_id' => $line->company_product_id,
                    'business_date' => $date,
                    'type' => StockMovementType::Delivery,
                    'packs' => $line->packs,
                    // Part of a pack, in pieces, beside the whole ones.
                    'loose' => (int) $line->loose,
                    // So the count knows what goes out of date and when,
                    // without tracing every pack back to its delivery.
                    'expires_on' => $line->expires_on?->toDateString(),
                    'batch_no' => $line->batch_no,
                    'unit_cost' => $line->rate,
                    'source_type' => Order::class,
                    'source_id' => $order->id,
                    'note' => $line->note,
                    'created_by' => $by->id,
                ]);

                $written++;
            }

            // Stock cannot go below zero: a delivery cannot be reduced past
            // what has already gone out of it.
            app(\App\Domain\Stock\StockLedger::class)->assertNotNegative($order->business, $before);

            return $written;
        });
    }

    /** Removes a delivery's movements, for when it is being re-recorded. */
    public function clearDelivery(Order $order): void
    {
        StockMovement::forBusiness($order->business_id)
            ->where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->delete();
    }

    /**
     * A correction by hand: opening stock, breakage, expiry, a recount.
     *
     * Takes the quantity the movement is, not the quantity to end up at —
     * "twelve went in the bin" rather than "make it 88" — because the first is
     * what happened and the second is only true until the next delivery.
     */
    public function adjust(
        Business $business,
        CompanyProduct $product,
        int $packs,
        StockMovementType $type,
        ?string $note,
        User $by,
        ?string $date = null,
        int $loose = 0,
        ?string $expiresOn = null,
        ?string $batchNo = null,
    ): StockMovement {
        if ($packs === 0 && $loose === 0) {
            throw new RuntimeException('An adjustment of nothing is not an adjustment.');
        }

        if ($product->business_id !== $business->id) {
            throw new RuntimeException('That product belongs to another business.');
        }

        $movement = StockMovement::create([
            'business_id' => $business->id,
            'company_product_id' => $product->id,
            'business_date' => $date ?? $business->today()->toDateString(),
            'type' => $type,
            'packs' => $packs,
            // Pieces out of an opened pack, where a count found those off too.
            'loose' => $loose,
            // Known for stock brought over from an old system, which wrote
            // the date and batch against the product.
            'expires_on' => $expiresOn,
            'batch_no' => $batchNo,
            'unit_cost' => $product->purchase_rate ?? $product->trade_price ?? Money::zero(),
            'note' => $note,
            'created_by' => $by->id,
        ]);

        // A quantity declared rather than delivered. Worth a record of who said
        // so and why, which is the whole reason a reason is asked for.
        activity()
            ->performedOn($movement)
            ->causedBy($by)
            ->withProperties([
                'product' => $product->label(),
                'packs' => $packs,
                'loose' => $loose,
                'kind' => $type->label(),
                'reason' => $note,
            ])
            ->event('stock.adjusted')
            ->log($packs > 0 || ($packs === 0 && $loose > 0) ? 'Stock added by hand' : 'Stock removed by hand');

        return $movement;
    }
}
