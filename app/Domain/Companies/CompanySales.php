<?php

namespace App\Domain\Companies;

use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\PosBillLine;
use App\Models\PosReturnLine;
use App\Support\Money;

/**
 * What one company's goods have sold for at the counter.
 *
 * Read from the bill lines themselves, the same way the Sales by product
 * report reads them, so the two always agree: a line's own total, with
 * returns of those products taken back off. A discount given on the whole
 * bill belongs to no one product, so it is not shared out here.
 */
class CompanySales
{
    /** @return array{sales: Money, returned: Money, net: Money, bills: int, packs: int, last: ?string} */
    public function for(Business $business, Company $company): array
    {
        $products = CompanyProduct::forBusiness($business)->where('company_id', $company->id)->select('id');

        $lines = PosBillLine::forBusiness($business)
            ->whereIn('company_product_id', $products)
            ->with('bill:id,business_date')
            ->get();

        $returns = PosReturnLine::forBusiness($business)
            ->whereIn('company_product_id', $products)
            ->get();

        $sales = Money::sum($lines->pluck('line_total'));
        $returned = Money::sum($returns->pluck('line_total'));

        $net = $sales->minus($returned);

        return [
            'sales' => $sales,
            'returned' => $returned,
            'net' => $net,
            'bills' => $lines->pluck('pos_bill_id')->unique()->count(),
            'packs' => (int) $lines->sum('quantity') - (int) $returns->sum('quantity'),
            'last' => $lines->map(fn (PosBillLine $l) => $l->bill?->business_date)->filter()->max()?->format('j M Y'),
        ];
    }
}
