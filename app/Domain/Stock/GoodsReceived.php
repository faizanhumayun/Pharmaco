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
    public function deliveries(Business $business, ?Carbon $from, ?int $companyId = null, ?int $limit = null): Collection
    {
        return $this->received($business, $from, $companyId)
            ->with(['company', 'receiptLines', 'receiver', 'purchaseLine.dailyEntry'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }

    /** How many deliveries the period holds, however many are listed. */
    public function deliveryCount(Business $business, ?Carbon $from, ?int $companyId = null): int
    {
        return $this->received($business, $from, $companyId)->count();
    }

    /**
     * What every delivery in the period came to, without loading the
     * deliveries themselves: a year of them is too many to hold at once.
     */
    public function receivedValue(Business $business, ?Carbon $from, ?int $companyId = null): Money
    {
        return OrderReceiptLine::query()
            ->whereIn('order_id', $this->received($business, $from, $companyId)->select('id'))
            ->select(['id', 'order_id', 'rate', 'packs', 'loose', 'units_per_pack', 'discount_percent'])
            ->with('order:id,discount_percent')
            ->get()
            ->reduce(fn (Money $total, OrderReceiptLine $line) => $total->plus(
                $line->amount($line->order->discount_percent)
            ), Money::zero());
    }

    private function received(Business $business, ?Carbon $from, ?int $companyId)
    {
        return Order::forBusiness($business)
            ->where('status', OrderStatus::Received)
            ->when($from, fn ($q) => $q->where('received_at', '>=', $from))
            // One company's deliveries, when the page is filtered to it.
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));
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
     * What the stock sold in the period cost — the other side of what was
     * received. Sales at the till less goods that came back, each at the cost
     * it was sold at, with loose pieces counted as their share of a pack.
     */
    /** @param  array<int, int>|null  $only  product ids, for one company's goods */
    public function soldValue(Business $business, ?Carbon $from, ?array $only = null): Money
    {
        return \App\Models\StockMovement::forBusiness($business)
            ->whereIn('type', [\App\Enums\StockMovementType::Sale, \App\Enums\StockMovementType::SaleReturn])
            ->when($from, fn ($q) => $q->where('business_date', '>=', $from->toDateString()))
            ->when($only !== null, fn ($q) => $q->whereIn('company_product_id', $only))
            // Summed per product and cost in the database: a pharmacy with a
            // year of bills has a hundred thousand movements, far too many to
            // load one by one. The sum is linear, so a group gives the same
            // figure as its movements would separately.
            ->groupBy('company_product_id', 'unit_cost')
            ->selectRaw('company_product_id, unit_cost, SUM(packs) as packs, SUM(loose) as loose')
            ->with('product:id,units_per_pack')
            ->get()
            ->reduce(function (Money $total, $group) {
                $per = max(1, (int) ($group->product?->units_per_pack ?? 1));
                // Sales are written as packs going out, so they are negative.
                $packs = -((int) $group->packs + (int) $group->loose / $per);

                return $total->plus(($group->unit_cost ?? Money::zero())->times($packs));
            }, Money::zero());
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
        // Whole packs, and the pieces left in opened ones, read together.
        [$held, $loose] = $ledger->heldByProduct($business);

        if ($held->isEmpty()) {
            return collect();
        }

        $received = $this->packsReceived($business, $from);

        $products = CompanyProduct::forBusiness($business)
            ->whereIn('id', $held->keys())
            ->with('company')
            ->get()
            ->keyBy('id');

        return $held
            ->map(function (int $packs, int $productId) use ($products, $received, $loose) {
                $product = $products->get($productId);

                // Nothing on hand is not stock held: a product taken off the
                // shelf stays in the catalogue but leaves this list.
                if ($product === null || ($packs === 0 && (int) ($loose[$productId] ?? 0) === 0)) {
                    return null;
                }

                $rate = $product->purchase_rate ?? $product->trade_price;
                $looseHeld = (int) ($loose[$productId] ?? 0);

                return [
                    'product' => $product,
                    'packs' => $packs,
                    'loose' => $looseHeld,
                    'received' => (int) ($received[$productId] ?? 0),
                    // At what a pack costs today, which is a sound enough
                    // estimate to check against the book value and is not a
                    // costing method — the ledger holds the value that counts.
                    // Loose pieces at their share of a pack, as everywhere else.
                    'value' => $rate?->times($packs)->plus(
                        \App\Models\PosBillLine::piece($rate, max(1, (int) $product->units_per_pack))->times($looseHeld)
                    ),
                ];
            })
            ->filter()
            ->sortBy(fn (array $row) => mb_strtolower($row['product']->brand_name))
            ->values();
    }

    /**
     * Packs received per product over the period, added up by the database.
     *
     * @return Collection<int, int>
     */
    public function packsReceived(Business $business, ?Carbon $from): Collection
    {
        return OrderReceiptLine::query()
            ->whereIn('order_id', $this->received($business, $from, null)->select('id'))
            ->whereNotNull('company_product_id')
            ->where('packs', '>', 0)
            ->groupBy('company_product_id')
            ->selectRaw('company_product_id, SUM(packs) as packs')
            ->pluck('packs', 'company_product_id')
            ->map(fn ($n) => (int) $n);
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
