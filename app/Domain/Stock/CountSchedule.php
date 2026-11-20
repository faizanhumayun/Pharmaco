<?php

namespace App\Domain\Stock;

use App\Enums\StockMovementType;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What to count today.
 *
 * Counting a thousand lines in a day is not possible, and counting them in
 * alphabetical order spends as long on a twenty-rupee syrup as on a case of
 * injections. So products earn their place by what they are worth and how fast
 * they move: the few lines that hold most of the money come round every month,
 * the long tail twice a year.
 *
 * Nothing here is stored. When a product was last counted is the date of its
 * last count, and what it is worth is what the shelf holds times what it cost —
 * both derived, so correcting a delivery or a count changes the schedule at
 * once rather than leaving a stale queue behind.
 */
class CountSchedule
{
    /**
     * How often each class of product should come round, in days.
     *
     * A is the handful of lines carrying most of the value, C the long tail.
     * The intervals are ordinary cycle-counting practice rather than anything
     * this business has asked for, and are the first thing to change if a
     * shopkeeper says the sheet comes round too often.
     */
    private const INTERVALS = ['A' => 30, 'B' => 90, 'C' => 180];

    public function __construct(private readonly StockLedger $stock) {}

    /**
     * The products due a count, most overdue first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function due(Business $business, int $limit = 15): Collection
    {
        return $this->ranked($business)
            ->filter(fn (array $row) => $row['due'])
            ->take($limit)
            ->values();
    }

    /**
     * Every product with its class, its value, and when it was last seen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ranked(Business $business): Collection
    {
        $today = $business->today();
        $onHand = $this->stock->onHandByProduct($business);
        $lastCounted = $this->lastCountedByProduct($business);
        $sold = $this->soldByProduct($business);

        $products = CompanyProduct::forBusiness($business)
            ->where('is_active', true)
            ->get();

        $rows = $products->map(function (CompanyProduct $product) use ($onHand, $sold) {
            $packs = max(0, (int) ($onHand[$product->id] ?? 0));
            $cost = $product->purchase_rate ?? Money::zero();

            /*
             * Worth counting = what is sitting there plus what went through it.
             * A product held in small numbers but sold every day is where the
             * count drifts, and value alone would never surface it.
             */
            $held = $cost->times($packs);
            $moved = $cost->times((int) ($sold[$product->id] ?? 0));

            return [
                'product' => $product,
                'packs' => $packs,
                'held' => $held,
                'weight' => (float) $held->plus($moved)->toDecimal(),
            ];
        })->sortByDesc('weight')->values();

        // A carries 70% of the weight, B the next 20%, C whatever is left —
        // the usual split, and the reason a shop counts its fast lines often.
        $total = array_sum($rows->pluck('weight')->all());
        $running = 0.0;

        return $rows->map(function (array $row) use (&$running, $total, $lastCounted, $today) {
            $running += $row['weight'];
            $share = $total > 0 ? $running / $total : 1;

            $class = $share <= 0.70 ? 'A' : ($share <= 0.90 ? 'B' : 'C');
            $interval = self::INTERVALS[$class];

            $last = $lastCounted[$row['product']->id] ?? null;
            $age = $last === null ? null : (int) $last->startOfDay()->diffInDays($today->copy()->startOfDay());

            return $row + [
                'class' => $class,
                'interval' => $interval,
                'last_counted' => $last,
                'age' => $age,
                // Never counted is always due; that is the backlog a new
                // business starts with and works through.
                'due' => $age === null || $age >= $interval,
                'overdue_by' => $age === null ? PHP_INT_MAX : $age - $interval,
            ];
        })->sortByDesc('overdue_by')->values();
    }

    /** When each product was last counted, whatever came of it. */
    public function lastCountedByProduct(Business $business): Collection
    {
        return StockCount::forBusiness($business)
            ->selectRaw('company_product_id, MAX(business_date) as last_date')
            ->groupBy('company_product_id')
            ->pluck('last_date', 'company_product_id')
            ->map(fn ($date) => \Illuminate\Support\Carbon::parse($date));
    }

    /** Packs sold in the last ninety days, as a measure of how fast it moves. */
    private function soldByProduct(Business $business): Collection
    {
        return StockMovement::forBusiness($business)
            ->where('type', StockMovementType::Sale)
            ->where('business_date', '>=', $business->today()->copy()->subDays(90)->toDateString())
            ->selectRaw('company_product_id, SUM(ABS(packs)) as packs')
            ->groupBy('company_product_id')
            ->pluck('packs', 'company_product_id');
    }
}
