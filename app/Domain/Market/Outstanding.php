<?php

namespace App\Domain\Market;

use App\Models\Business;
use App\Models\CollectionAllocation;
use App\Models\Pharmacy;
use App\Models\PosBill;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the market still owes, bill by bill.
 *
 * A bill's own figures never change, so what is still owed on it is what went
 * unpaid at the counter less everything collected against it since. Derived,
 * never stored — the same rule the ledger follows, for the same reason: a
 * stored "outstanding" column drifts the moment anything is amended.
 */
class Outstanding
{
    /** Bills with something still owing, oldest first. */
    public function billsFor(Business $business, Pharmacy $pharmacy): Collection
    {
        return $this->bills($business, $pharmacy)
            ->filter(fn (PosBill $bill) => $this->onBill($bill)->isPositive())
            ->values();
    }

    /** Every bill of this customer's that began life owing something. */
    public function bills(Business $business, ?Pharmacy $pharmacy = null): Collection
    {
        return PosBill::forBusiness($business)
            ->when($pharmacy !== null, fn ($q) => $q->where('customer_name', $pharmacy->name))
            ->whereRaw('total > received')
            ->orderBy('business_date')
            ->orderBy('id')
            ->get()
            ->each(fn (PosBill $bill) => $bill->setAttribute('collected', $this->collectedOn($bill)));
    }

    /** What is still owed on one bill. */
    public function onBill(PosBill $bill): Money
    {
        $collected = $bill->getAttribute('collected') ?? $this->collectedOn($bill);

        return $bill->credit()->minus($collected);
    }

    /** What has been brought in against one bill since it was rung up. */
    public function collectedOn(PosBill $bill): Money
    {
        return Money::sum(
            CollectionAllocation::query()->where('pos_bill_id', $bill->id)->pluck('amount')
        );
    }

    /**
     * Every collection allocation in one query, keyed by bill.
     *
     * The list screen shows hundreds of bills, and asking per bill is how a
     * page that opened in 80ms starts taking thirty seconds.
     *
     * @return \Illuminate\Support\Collection<int, Money>
     */
    public function collectedByBill(Business $business): Collection
    {
        return CollectionAllocation::query()
            ->join('pos_bills', 'pos_bills.id', '=', 'collection_allocations.pos_bill_id')
            ->where('pos_bills.business_id', $business->id)
            ->selectRaw('pos_bill_id, SUM(collection_allocations.amount) as total')
            ->groupBy('pos_bill_id')
            ->pluck('total', 'pos_bill_id')
            ->map(fn ($total) => Money::of($total));
    }

    /** How long a bill has been waiting, in days. */
    public function ageOf(PosBill $bill, Carbon $today): int
    {
        return (int) $bill->business_date->startOfDay()->diffInDays($today->copy()->startOfDay());
    }

    /** The bucket a waiting bill falls in. */
    public function bandFor(int $days): string
    {
        return match (true) {
            $days <= 7 => 'This week',
            $days <= 30 => '8–30 days',
            default => 'Over 30 days',
        };
    }
}
