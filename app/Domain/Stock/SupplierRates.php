<?php

namespace App\Domain\Stock;

use App\Enums\StockMovementType;
use App\Models\Business;
use App\Models\Company;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * Who has delivered a product, and at what rate.
 *
 * Each supplier's rate is the one on its latest delivery, the same reading as
 * the product page. Lists ask for a page of products at a time, so this is a
 * few queries for the whole page rather than some per row.
 */
class SupplierRates
{
    /**
     * Per product, every supplier that has delivered it, cheapest first.
     * Only products with more than one supplier are returned.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Collection<int, array{company: Company, rate: ?\App\Support\Money}>>
     */
    public function cheapestFirst(Business $business, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $movements = StockMovement::forBusiness($business)
            ->whereIn('company_product_id', $ids)
            ->where('type', StockMovementType::Delivery)
            ->where('source_type', (new Order)->getMorphClass())
            ->orderBy('business_date')
            ->orderBy('id')
            ->get(['id', 'company_product_id', 'source_id', 'unit_cost', 'business_date']);

        $orders = Order::forBusiness($business)
            ->whereIn('id', $movements->pluck('source_id')->unique())
            ->pluck('company_id', 'id');

        $companies = Company::forBusiness($business)
            ->whereIn('id', $orders->unique())
            ->get()
            ->keyBy('id');

        return $movements
            ->groupBy('company_product_id')
            ->map(fn (Collection $rows) => $rows
                ->groupBy(fn ($m) => $orders[$m->source_id] ?? 0)
                ->filter(fn ($rows, $companyId) => $companies->has($companyId))
                ->map(fn ($rows, $companyId) => ['company' => $companies[$companyId], 'rate' => $rows->last()->unit_cost])
                ->sortBy(fn ($r) => $r['rate'] === null ? PHP_FLOAT_MAX : (float) $r['rate']->toDecimal())
                ->values())
            ->filter(fn (Collection $rows) => $rows->count() > 1);
    }
}
