<?php

namespace App\Domain\Stock;

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\Order;
use App\Models\OrderReceiptLine;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What is held, and what came in to make it so.
 *
 * Quantities held come from {@see StockLedger}; what arrived in a period comes
 * from the deliveries themselves. Until the point of sale writes movements of
 * its own, nothing records packs leaving except an adjustment made by hand, so
 * a held figure is only as true as the adjustments behind it — which is what
 * counting is for, and why the book value stays the financial answer.
 */
class GoodsReceived
{
    /** @return array{from: ?Carbon, to: Carbon, label: string} */
    public function period(Business $business, ?string $key): array
    {
        $today = $business->today();

        return match ($key) {
            'month' => ['from' => $today->copy()->startOfMonth(), 'to' => $today, 'label' => 'This month'],
            'quarter' => ['from' => $today->copy()->subMonthsNoOverflow(3)->startOfDay(), 'to' => $today, 'label' => 'Last 3 months'],
            default => ['from' => null, 'to' => $today, 'label' => 'All time'],
        };
    }

    /**
     * Deliveries received in the period, newest first.
     *
     * @return Collection<int, Order>
     */
    public function deliveries(Business $business, ?Carbon $from): Collection
    {
        return Order::forBusiness($business)
            ->where('status', OrderStatus::Received)
            ->when($from, fn ($q) => $q->where('received_at', '>=', $from))
            ->with(['company', 'receiptLines', 'receiver', 'purchaseLine.dailyEntry'])
            ->orderByDesc('received_at')
            ->get();
    }

    /**
     * What those deliveries came to in total.
     *
     * @param  Collection<int, Order>  $deliveries
     */
    public function value(Collection $deliveries): Money
    {
        return Money::sum($deliveries->map(fn (Order $order) => $order->receivedTotal()));
    }

    /**
     * A row per product: what is held now, and what came in this period.
     *
     * Products that have never moved are left out — a catalogue of thousands
     * with a column of zeroes answers nothing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function onHand(Business $business, StockLedger $ledger, ?Carbon $from): Collection
    {
        $held = $ledger->onHandByProduct($business);

        if ($held->isEmpty()) {
            return collect();
        }

        $received = $this->items($business, $from)->keyBy('product_id');

        $products = CompanyProduct::forBusiness($business)
            ->whereIn('id', $held->keys())
            ->with('company')
            ->get()
            ->keyBy('id');

        return $held
            ->map(function (int $packs, int $productId) use ($products, $received) {
                $product = $products->get($productId);

                if ($product === null) {
                    return null;
                }

                $rate = $product->purchase_rate ?? $product->trade_price;

                return [
                    'product' => $product,
                    'packs' => $packs,
                    'received' => (int) ($received->get($productId)['packs'] ?? 0),
                    // At what a pack costs today, which is a sound enough
                    // estimate to check against the book value and is not a
                    // costing method — the ledger holds the value that counts.
                    'value' => $rate?->times($packs),
                ];
            })
            ->filter()
            ->sortBy(fn (array $row) => mb_strtolower($row['product']->brand_name))
            ->values();
    }

    /**
     * Packs received per product over the period.
     *
     * Grouped by the catalogue product where a line names one, and by its
     * brand name otherwise — a line typed by hand still counts, it simply has
     * nothing to be grouped against.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function items(Business $business, ?Carbon $from): Collection
    {
        $lines = OrderReceiptLine::query()
            ->whereHas('order', function ($query) use ($business, $from) {
                $query->forBusiness($business)
                    ->where('status', OrderStatus::Received)
                    ->when($from, fn ($q) => $q->where('received_at', '>=', $from));
            })
            ->where('packs', '>', 0)
            // Loaded because each line's discount falls back to its order's.
            ->with('order:id,discount_percent')
            ->get();

        return $lines
            ->groupBy(fn (OrderReceiptLine $line) => $line->company_product_id ?? 'x:'.mb_strtolower($line->brand_name))
            ->map(fn (Collection $group) => [
                'product_id' => $group->first()->company_product_id,
                'label' => $group->first()->label(),
                'generic_name' => $group->first()->generic_name,
                'pack_size' => $group->first()->pack_size,
                'packs' => (int) $group->sum('packs'),
                'deliveries' => $group->pluck('order_id')->unique()->count(),
                'value' => Money::sum($group->map(fn (OrderReceiptLine $line) => $line->amount(
                    $line->order->discount_percent,
                ))),
            ])
            ->sortByDesc('packs')
            ->values();
    }
}
