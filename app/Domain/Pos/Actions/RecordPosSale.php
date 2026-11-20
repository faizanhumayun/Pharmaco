<?php

namespace App\Domain\Pos\Actions;

use App\Domain\Daily\DayWriter;
use App\Domain\Pos\BillLines;
use App\Domain\Stock\StockLedger;
use App\Enums\StockMovementType;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\BusinessPaymentMethod;
use App\Models\Pharmacy;
use App\Models\PosBill;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rings up a sale at the counter.
 *
 * Three things happen together, or none of them do: the bill is written, the
 * goods leave stock, and the day's entry gains a sale line for it. A sale of
 * more than the books hold is refused outright — stock never goes below zero.
 *
 * A pack that can be opened may be sold whole, by the piece, or both on one
 * line; the piece price is the pack price divided by what is in the pack. The day is
 * still the financial document — nothing here posts to the ledger itself.
 *
 * The bill knows what each item cost, so it also carries its own margin into
 * the day's gross profit. That is the difference between a counter that can
 * tell you what it earned and one that can only tell you what it took.
 */
class RecordPosSale
{
    public function __construct(
        private readonly DayWriter $day,
        private readonly StockLedger $stock,
        private readonly BillLines $lines,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: int, loose?: int, unit_price: string}>  $items
     * @param  BusinessPaymentMethod|null  $via  how the money was taken; null is cash
     */
    public function handle(
        Business $business,
        array $items,
        Money $received,
        ?string $customer,
        ?string $note,
        User $by,
        ?Carbon $date = null,
        ?Money $discount = null,
        array $shownAs = [],
        ?BusinessPaymentMethod $via = null,
    ): PosBill {
        $date ??= $business->today();
        $discount ??= Money::zero();

        if ($via !== null && ($via->business_id !== $business->id || ! $via->is_active)) {
            throw new LedgerException('That payment method is not switched on for this business.');
        }

        // Nothing taken means there is nothing to say about how it was taken.
        if (! $received->isPositive()) {
            $via = null;
        }

        return DB::transaction(function () use ($business, $items, $received, $customer, $note, $by, $date, $discount, $shownAs, $via) {
            ['lines' => $lines, 'total' => $total, 'cost' => $cost] = $this->lines->build($business, $items);

            $total = $total->minus($discount);

            if ($total->isNegative()) {
                throw new LedgerException('The discount is more than the bill.');
            }

            $bill = PosBill::create([
                'business_id' => $business->id,
                'business_date' => $date->toDateString(),
                // Counts from 1 per business. Inside the transaction, so two
                // tills cannot take the same number.
                'bill_no' => (int) PosBill::forBusiness($business)->lockForUpdate()->max('bill_no') + 1,
                // Its place in the day, which is what the reference shows.
                'daily_no' => PosBill::nextDailyNo($business, $date, lock: true),
                'customer_name' => $customer !== null && trim($customer) !== '' ? trim($customer) : null,
                'total' => $total->toDecimal(),
                'discount' => $discount->toDecimal(),
                'received' => $received->toDecimal(),
                'business_payment_method_id' => $via?->id,
                'cost' => $cost->toDecimal(),
                'note' => $note,
                // Presentation, kept so a reopened bill comes back the way it
                // went out, and so "it did not show me X" has an answer.
                'as_net_bill' => (bool) ($shownAs['net'] ?? false),
                'asked_for_print' => (bool) ($shownAs['print'] ?? false),
                'asked_for_balance' => (bool) ($shownAs['balance'] ?? false),
                'created_by' => $by->id,
            ]);

            foreach ($lines as $line) {
                $bill->lines()->create($line);
            }

            // Out of stock, against the bill, so the movement says which sale.
            foreach ($bill->lines as $line) {
                StockMovement::create([
                    'business_id' => $business->id,
                    'company_product_id' => $line->company_product_id,
                    'business_date' => $date->toDateString(),
                    'type' => StockMovementType::Sale,
                    'packs' => -$line->quantity,
                    'loose' => -$line->loose_quantity,
                    'unit_cost' => $line->unit_cost->toDecimal(),
                    'source_type' => $bill->getMorphClass(),
                    'source_id' => $bill->id,
                    'note' => 'Sold on bill ' . $bill->reference(),
                    'created_by' => $by->id,
                ]);
            }

            // Refused here, after the movements and inside the transaction, so
            // a sale of what is not there undoes itself completely.
            $this->stock->assertNotNegative($business, $bill->lines->pluck('company_product_id')->all());

            // Onto the day: one sale line, and this bill's own margin.
            $entry = $this->day->write($business, $date, function (array $data) use ($bill) {
                $data['sales'][] = [
                    'pharmacy' => $bill->customer_name ?? '',
                    'invoice_no' => $bill->reference(),
                    'amount' => $bill->total->toDecimal(),
                    'received' => $bill->received->toDecimal(),
                    'via' => $bill->business_payment_method_id,
                ];

                $data['gross_profit'] = Money::of($data['gross_profit'] ?? 0)
                    ->plus($bill->margin())->toDecimal();

                return $data;
            }, $by);

            // The day's sale line found or made the customer by name; the bill
            // now points at that customer rather than only repeating the name.
            $bill->forceFill([
                'daily_entry_id' => $entry->id,
                'pharmacy_id' => $bill->customer_name === null ? null : Pharmacy::forBusiness($business)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($bill->customer_name)])
                    ->value('id'),
            ])->save();

            activity()
                ->performedOn($bill)
                ->causedBy($by)
                ->withProperties([
                    'bill' => $bill->reference(),
                    'items' => count($lines),
                    'total' => $bill->total->toDecimal(),
                    'received' => $bill->received->toDecimal(),
                    'margin' => $bill->margin()->toDecimal(),
                ])
                ->event('pos.sold')
                ->log('Sale at the counter ' . $bill->reference());

            return $bill->fresh('lines');
        });
    }
}
