<?php

namespace App\Domain\Pos\Actions;

use App\Domain\Daily\DayWriter;
use App\Enums\StockMovementType;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\CompanyProduct;
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
 * goods leave stock, and the day's entry gains a sale line for it. The day is
 * still the financial document — nothing here posts to the ledger itself.
 *
 * The bill knows what each item cost, so it also carries its own margin into
 * the day's gross profit. That is the difference between a counter that can
 * tell you what it earned and one that can only tell you what it took.
 */
class RecordPosSale
{
    public function __construct(private readonly DayWriter $day) {}

    /**
     * @param  array<int, array{product_id: int, quantity: int, unit_price: string}>  $items
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
    ): PosBill {
        $date ??= $business->today();
        $discount ??= Money::zero();

        if ($items === []) {
            throw new LedgerException('A bill with nothing on it is not a sale.');
        }

        // Priced and costed from this business's own catalogue, never from
        // what the till was told the price was.
        $products = CompanyProduct::forBusiness($business)
            ->whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        return DB::transaction(function () use ($business, $items, $products, $received, $customer, $note, $by, $date, $discount) {
            $lines = [];
            $total = Money::zero();
            $cost = Money::zero();

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);

                if ($product === null) {
                    throw new LedgerException('One of the items is not in this business\'s product list.');
                }

                $quantity = (int) $item['quantity'];

                if ($quantity < 1) {
                    throw new LedgerException("{$product->brand_name}: a sale of nothing is not a sale.");
                }

                $unitPrice = Money::of($item['unit_price'] ?? $product->mrp ?? 0);
                $unitCost = $product->purchase_rate ?? $product->trade_price ?? Money::zero();
                $lineTotal = $unitPrice->times($quantity);

                $lines[] = [
                    'business_id' => $business->id,
                    'company_product_id' => $product->id,
                    'name' => $product->label(),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice->toDecimal(),
                    'unit_cost' => $unitCost->toDecimal(),
                    'line_total' => $lineTotal->toDecimal(),
                ];

                $total = $total->plus($lineTotal);
                $cost = $cost->plus($unitCost->times($quantity));
            }

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
                'customer_name' => $customer !== null && trim($customer) !== '' ? trim($customer) : null,
                'total' => $total->toDecimal(),
                'discount' => $discount->toDecimal(),
                'received' => $received->toDecimal(),
                'cost' => $cost->toDecimal(),
                'note' => $note,
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
                    'unit_cost' => $line->unit_cost->toDecimal(),
                    'source_type' => $bill->getMorphClass(),
                    'source_id' => $bill->id,
                    'note' => 'Sold on bill ' . $bill->reference(),
                    'created_by' => $by->id,
                ]);
            }

            // Onto the day: one sale line, and this bill's own margin.
            $entry = $this->day->write($business, $date, function (array $data) use ($bill, $customer) {
                $data['sales'][] = [
                    'pharmacy' => $customer ?? '',
                    'invoice_no' => $bill->reference(),
                    'amount' => $bill->total->toDecimal(),
                    'received' => $bill->received->toDecimal(),
                ];

                $data['gross_profit'] = Money::of($data['gross_profit'] ?? 0)
                    ->plus($bill->margin())->toDecimal();

                return $data;
            }, $by);

            $bill->forceFill(['daily_entry_id' => $entry->id])->save();

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
