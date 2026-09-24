<?php

namespace App\Domain\Market\Actions;

use App\Domain\Daily\DayWriter;
use App\Domain\Market\Outstanding;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\DailyEntry;
use App\Models\Pharmacy;
use App\Models\PosBill;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money brought back from a customer.
 *
 * A bill is sold at the counter and the goods go out on the van; the cash
 * comes back hours or days later, and it is rarely the bill's exact figure —
 * short because they are short, or over because they are clearing old dues.
 *
 * So this is not an edit of the bill. The bill is a true record of what was
 * sold and what was paid at the time, and it never changes. This is a separate
 * event, dated the day the cash actually arrived, credited to the customer's
 * own ledger. What it settles is worked out afterwards, oldest bill first.
 */
class RecordCollection
{
    public function __construct(
        private readonly DayWriter $day,
        private readonly Outstanding $outstanding,
    ) {}

    public function handle(
        Business $business,
        Pharmacy $pharmacy,
        Money $amount,
        User $by,
        ?Carbon $date = null,
        ?PosBill $against = null,
        ?string $note = null,
    ): DailyEntry {
        $date ??= $business->today();

        if (! $amount->isPositive()) {
            throw new LedgerException('A collection has to be an amount of money.');
        }

        if ($against !== null && $against->business_id !== $business->id) {
            throw new LedgerException('That bill belongs to another business.');
        }

        return DB::transaction(function () use ($business, $pharmacy, $amount, $by, $date, $against, $note) {
            /*
             * Worked out before the day is touched, against the bills as they
             * stand. The named bill is settled first even when it is not the
             * oldest — the collector went out for that one — and whatever is
             * left walks back through the rest from the oldest.
             */
            $allocations = $this->allocate($business, $pharmacy, $amount, $against);

            return $this->day->write($business, $date, function (array $data) use ($pharmacy, $amount, $allocations, $note) {
                $data['collections'][] = [
                    'pharmacy' => $pharmacy->name,
                    'amount' => $amount->toDecimal(),
                    'note' => $note,
                    'allocations' => $allocations,
                ];

                return $data;
            }, $by);
        });
    }

    /**
     * How this money is applied: the bill it was collected for, then the
     * oldest still owing, until the money runs out. Anything beyond every open
     * bill is allocated nowhere and simply sits on the account, which is the
     * truthful answer — it is theirs in credit until they buy again.
     *
     * @return array<int, array{pos_bill_id: int, amount: string}>
     */
    private function allocate(Business $business, Pharmacy $pharmacy, Money $amount, ?PosBill $against): array
    {
        $bills = $this->outstanding->billsFor($business, $pharmacy);

        if ($against !== null) {
            $bills = $bills->sortByDesc(fn (PosBill $bill) => $bill->id === $against->id)->values();
        }

        $left = $amount;
        $allocations = [];

        foreach ($bills as $bill) {
            if (! $left->isPositive()) {
                break;
            }

            $owed = $this->outstanding->onBill($bill);

            if (! $owed->isPositive()) {
                continue;
            }

            $applied = $left->lessThan($owed) ? $left : $owed;

            $allocations[] = [
                'pos_bill_id' => $bill->id,
                'amount' => $applied->toDecimal(),
            ];

            $left = $left->minus($applied);
        }

        return $allocations;
    }
}
