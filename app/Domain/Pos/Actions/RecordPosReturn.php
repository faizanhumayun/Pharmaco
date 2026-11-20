<?php

namespace App\Domain\Pos\Actions;

use App\Domain\Daily\DayWriter;
use App\Domain\Market\Outstanding;
use App\Enums\StockMovementType;
use App\Exceptions\LedgerException;
use App\Models\PosBill;
use App\Models\PosBillLine;
use App\Models\PosReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Takes goods back against a bill.
 *
 * The bill is not touched — it stays the record of what was sold. A return is
 * a document of its own that does three things together: the goods go back
 * into stock, the day's entry gains a return for their value, and the profit
 * the sale had booked on them is handed back.
 *
 * How it is settled is not a choice made at the till. Whatever the customer
 * still owes on that bill comes down first; only what is left after that is
 * handed back in cash. Refunding cash to someone who has not yet paid for the
 * goods would be paying them to take credit.
 */
class RecordPosReturn
{
    public function __construct(
        private readonly DayWriter $day,
        private readonly Outstanding $outstanding,
    ) {}

    /**
     * @param  array<int, array{line_id: int, quantity?: int, loose?: int}>  $items
     */
    public function handle(PosBill $bill, array $items, ?string $reason, User $by, ?Carbon $date = null): PosReturn
    {
        $business = $bill->business;
        $date ??= $business->today();

        // Compared as calendar dates: one is in the business's timezone and
        // the other is a bare date, so comparing instants would be wrong.
        if ($date->toDateString() < $bill->business_date->toDateString()) {
            throw new LedgerException('Goods cannot come back before they were sold.');
        }

        return DB::transaction(function () use ($bill, $business, $items, $reason, $by, $date) {
            $bill = PosBill::forBusiness($business)->lockForUpdate()->findOrFail($bill->id);
            $bill->load(['lines', 'returns.lines', 'pharmacy']);

            // What has already come back, line by line, in pieces.
            $alreadyBack = $bill->returns->flatMap->lines
                ->groupBy('pos_bill_line_id')
                ->map(fn ($lines) => $lines->sum(fn ($l) => $l->pieces()));

            $rows = [];
            $gross = Money::zero();
            $grossCost = Money::zero();

            foreach ($items as $item) {
                /** @var PosBillLine|null $line */
                $line = $bill->lines->firstWhere('id', (int) ($item['line_id'] ?? 0));

                if ($line === null) {
                    throw new LedgerException('One of those items is not on this bill.');
                }

                $size = max(1, $line->units_per_pack);
                $quantity = max(0, (int) ($item['quantity'] ?? 0));
                $loose = $size > 1 ? max(0, (int) ($item['loose'] ?? 0)) : 0;
                $quantity += intdiv($loose, $size);
                $loose %= $size;
                $pieces = $quantity * $size + $loose;

                if ($pieces === 0) {
                    continue;
                }

                $left = $line->pieces() - (int) ($alreadyBack[$line->id] ?? 0);

                if ($pieces > $left) {
                    throw new LedgerException(sprintf(
                        '%s: only %s of this bill\'s quantity is left to return.',
                        $line->name,
                        $size > 1 ? intdiv($left, $size).' + '.($left % $size).' loose' : $left,
                    ));
                }

                $value = $line->unit_price->times($quantity)->plus($line->piecePrice()->times($loose));
                $cost = $line->unit_cost->times($quantity)
                    ->plus(PosBillLine::piece($line->unit_cost, $size)->times($loose));

                $rows[] = [
                    'business_id' => $business->id,
                    'pos_bill_line_id' => $line->id,
                    'company_product_id' => $line->company_product_id,
                    'name' => $line->name,
                    'quantity' => $quantity,
                    'loose_quantity' => $loose,
                    'units_per_pack' => $size,
                    'unit_price' => $line->unit_price->toDecimal(),
                    'unit_cost' => $line->unit_cost->toDecimal(),
                    'line_total' => $value->toDecimal(),
                ];

                $gross = $gross->plus($value);
                $grossCost = $grossCost->plus($cost);
            }

            if ($rows === []) {
                throw new LedgerException('Nothing was entered as coming back.');
            }

            /*
             * A bill-level discount was spread over every line, so the goods
             * coming back are worth their share of what was actually charged,
             * not their list price. Without this a discounted bill returned in
             * full would hand back more than was ever taken.
             */
            $listed = Money::sum($bill->lines->pluck('line_total'));
            $total = $listed->isZero() || $bill->discount->isZero()
                ? $gross
                : Money::of(bcdiv(bcmul($gross->toDecimal(), $bill->total->toDecimal(), 6), $listed->toDecimal(), 2));

            // Owed on this bill comes down first; the rest is cash back.
            $owed = $this->outstanding->onBill($bill);
            $onAccount = $total->lessThan($owed) ? $total : $owed;
            $refunded = $total->minus($onAccount);

            $return = PosReturn::create([
                'business_id' => $business->id,
                'pos_bill_id' => $bill->id,
                'pharmacy_id' => $bill->pharmacy_id,
                'business_date' => $date->toDateString(),
                'return_no' => (int) PosReturn::forBusiness($business)->lockForUpdate()->max('return_no') + 1,
                'total' => $total->toDecimal(),
                'cost' => $grossCost->toDecimal(),
                'refunded' => $refunded->toDecimal(),
                'reason' => $reason,
                'created_by' => $by->id,
            ]);

            foreach ($rows as $row) {
                $line = $return->lines()->create($row);

                // A line typed by hand names no product; there is nothing to
                // put back on a shelf the books do not know about.
                if ($line->company_product_id === null) {
                    continue;
                }

                StockMovement::create([
                    'business_id' => $business->id,
                    'company_product_id' => $line->company_product_id,
                    'business_date' => $date->toDateString(),
                    'type' => StockMovementType::SaleReturn,
                    'packs' => $line->quantity,
                    'loose' => $line->loose_quantity,
                    'unit_cost' => $line->unit_cost->toDecimal(),
                    'source_type' => $return->getMorphClass(),
                    'source_id' => $return->id,
                    'note' => 'Returned on '.$return->reference().' against bill '.$bill->reference(),
                    'created_by' => $by->id,
                ]);
            }

            // Onto the day: a return for its value, and the profit handed back.
            $entry = $this->day->write($business, $date, function (array $data) use ($return, $bill) {
                $data['returns'][] = [
                    'pharmacy' => $bill->pharmacy?->name ?? $bill->customer_name ?? '',
                    'reference' => $return->reference().' / '.$bill->reference(),
                    'amount' => $return->total->toDecimal(),
                    'refunded' => $return->refunded->toDecimal(),
                ];

                $data['gross_profit'] = Money::of($data['gross_profit'] ?? 0)
                    ->minus($return->margin())->toDecimal();

                return $data;
            }, $by);

            $return->forceFill(['daily_entry_id' => $entry->id])->save();

            activity()
                ->performedOn($return)
                ->causedBy($by)
                ->withProperties([
                    'return' => $return->reference(),
                    'bill' => $bill->reference(),
                    'total' => $return->total->toDecimal(),
                    'refunded' => $return->refunded->toDecimal(),
                    'on_account' => $return->onAccount()->toDecimal(),
                    'reason' => $reason,
                ])
                ->event('pos.returned')
                ->log('Goods returned '.$return->reference().' against bill '.$bill->reference());

            return $return->fresh('lines');
        });
    }
}
