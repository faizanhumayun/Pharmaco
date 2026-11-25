<?php

namespace App\Domain\Pos\Actions;

use App\Domain\Daily\DayWriter;
use App\Domain\Market\Outstanding;
use App\Domain\Pos\BillLines;
use App\Domain\Stock\StockLedger;
use App\Enums\StockMovementType;
use App\Exceptions\LedgerException;
use App\Models\BusinessPaymentMethod;
use App\Models\Pharmacy;
use App\Models\PosBill;
use App\Models\PosBillRevision;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a bill after it was saved.
 *
 * The bill ends up as it should have been, and how it stood before is kept as
 * a revision — every line added, taken off or changed, who did it, and why. So
 * a bill can be put right without anyone being able to say afterwards that it
 * always read that way.
 *
 * Everything the bill fed follows it in the same transaction: the goods moved
 * out of stock, the sale line on its day, and that day's profit. The day is
 * amended the way any correction to it is — reversed and reposted in the
 * ledger — and a closed day refuses, as it does for everything.
 */
class EditPosBill
{
    public function __construct(
        private readonly DayWriter $day,
        private readonly StockLedger $stock,
        private readonly BillLines $lines,
        private readonly Outstanding $outstanding,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity?: int, loose?: int, unit_price?: string}>  $items
     */
    public function handle(
        PosBill $bill,
        array $items,
        Money $discount,
        Money $received,
        ?string $customer,
        ?BusinessPaymentMethod $via,
        string $reason,
        User $by,
    ): PosBill {
        $business = $bill->business;
        $reason = trim($reason);

        if ($reason === '') {
            throw new LedgerException('Enter the reason for changing this bill.');
        }

        if ($via !== null && ($via->business_id !== $business->id || ! $via->is_active)) {
            throw new LedgerException('This payment method is not enabled for this business.');
        }

        if (! $received->isPositive()) {
            $via = null;
        }

        return DB::transaction(function () use ($bill, $business, $items, $discount, $received, $customer, $via, $reason, $by) {
            $bill = PosBill::forBusiness($business)->lockForUpdate()->findOrFail($bill->id);
            $bill->load(['lines', 'returns', 'paymentMethod.method']);

            if ($bill->returns->isNotEmpty()) {
                throw new LedgerException(
                    'Items have already been returned against this bill, so it cannot be edited. '
                    .'Record any further return as a new return.'
                );
            }

            $before = $this->snapshot($bill);
            $oldReference = $bill->reference();
            $oldMargin = $bill->margin();
            $touched = $bill->lines->pluck('company_product_id')->all();

            // A line that stays on the bill keeps the cost it was sold at.
            $knownCosts = $bill->lines->whereNotNull('company_product_id')
                ->mapWithKeys(fn ($l) => [$l->company_product_id => $l->unit_cost->toDecimal()])->all();

            ['lines' => $lines, 'total' => $total, 'cost' => $cost] = $this->lines->build($business, $items, $knownCosts);

            $total = $total->minus($discount);

            if ($total->isNegative()) {
                throw new LedgerException('The discount cannot be more than the bill total.');
            }

            // Nor so much that the bill sells below what its goods cost:
            // the same floor as each line's price, for the bill as a whole.
            if ($discount->isPositive() && $total->lessThan($cost)) {
                throw new LedgerException(sprintf(
                    'The discount takes the bill below the purchase price of its goods (Rs. %s). The most discount on this bill is Rs. %s.',
                    $cost->format(),
                    $total->plus($discount)->minus($cost)->format(),
                ));
            }

            // A walk-in is a stranger: there is nobody to collect from later,
            // so a bill with no customer on it is paid in full or not saved.
            if (($customer === null || trim($customer) === '') && $received->lessThan($total)) {
                throw new LedgerException('A walk-in bill must be paid in full. Add a customer to keep an amount pending.');
            }

            // Money already collected against this bill cannot be left paying
            // for more than the bill now leaves owing.
            $collected = $this->outstanding->collectedOn($bill);
            $newCredit = $total->minus($received);
            $newCredit = $newCredit->isNegative() ? Money::zero() : $newCredit;

            if ($collected->greaterThan($newCredit)) {
                throw new LedgerException(sprintf(
                    'Rs. %s has already been collected against this bill. After this change, the pending amount would be only Rs. %s.',
                    $collected->format(), $newCredit->format(),
                ));
            }

            $customer = $customer !== null && trim($customer) !== '' ? trim($customer) : null;

            $bill->lines()->delete();

            foreach ($lines as $line) {
                $bill->lines()->create($line);
            }

            $bill->forceFill([
                'customer_name' => $customer,
                'total' => $total->toDecimal(),
                'discount' => $discount->toDecimal(),
                'received' => $received->toDecimal(),
                'business_payment_method_id' => $via?->id,
                'cost' => $cost->toDecimal(),
            ])->save();

            $bill->load('lines');

            // The goods that left for this bill, rewritten to match it.
            StockMovement::forBusiness($business)
                ->where('source_type', $bill->getMorphClass())
                ->where('source_id', $bill->id)
                ->delete();

            foreach ($bill->lines as $line) {
                StockMovement::create([
                    'business_id' => $business->id,
                    'company_product_id' => $line->company_product_id,
                    'business_date' => $bill->business_date->toDateString(),
                    'type' => StockMovementType::Sale,
                    'packs' => -$line->quantity,
                    'loose' => -$line->loose_quantity,
                    'unit_cost' => $line->unit_cost->toDecimal(),
                    'source_type' => $bill->getMorphClass(),
                    'source_id' => $bill->id,
                    'note' => 'Sold on bill '.$bill->reference(),
                    'created_by' => $by->id,
                ]);
            }

            $this->stock->assertNotNegative(
                $business,
                [...$touched, ...$bill->lines->pluck('company_product_id')->all()],
            );

            // The day: this bill's sale line replaced, and its profit moved by
            // the difference.
            $newMargin = $bill->margin();

            $this->day->write($business, $bill->business_date, function (array $data) use ($bill, $oldReference, $oldMargin, $newMargin) {
                $row = [
                    'pharmacy' => $bill->customer_name ?? '',
                    'invoice_no' => $bill->reference(),
                    'amount' => $bill->total->toDecimal(),
                    'received' => $bill->received->toDecimal(),
                    'via' => $bill->business_payment_method_id,
                ];

                $found = false;

                foreach ($data['sales'] ?? [] as $i => $sale) {
                    if (($sale['invoice_no'] ?? null) === $oldReference) {
                        $data['sales'][$i] = $row;
                        $found = true;
                        break;
                    }
                }

                if (! $found) {
                    $data['sales'][] = $row;
                }

                $data['gross_profit'] = Money::of($data['gross_profit'] ?? 0)
                    ->minus($oldMargin)->plus($newMargin)->toDecimal();

                return $data;
            }, $by);

            $bill->forceFill([
                'pharmacy_id' => $bill->customer_name === null ? null : Pharmacy::forBusiness($business)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($bill->customer_name)])
                    ->value('id'),
            ])->save();

            $bill->load('paymentMethod.method');
            $after = $this->snapshot($bill);

            $revision = PosBillRevision::create([
                'business_id' => $business->id,
                'pos_bill_id' => $bill->id,
                'revision_no' => (int) PosBillRevision::forBusiness($business)->where('pos_bill_id', $bill->id)->max('revision_no') + 1,
                'reason' => $reason,
                'before' => $before,
                'after' => $after,
                'changes' => $this->diff($before, $after),
                'edited_by' => $by->id,
            ]);

            activity()
                ->performedOn($bill)
                ->causedBy($by)
                ->withProperties([
                    'bill' => $bill->reference(),
                    'revision' => $revision->revision_no,
                    'reason' => $reason,
                    'changes' => $revision->changes,
                ])
                ->event('pos.edited')
                ->log('Bill '.$bill->reference().' corrected');

            return $bill->fresh('lines');
        });
    }

    /**
     * The bill as it stands, in a form that still reads years later — names
     * and figures, not ids that may since have been renamed.
     *
     * @return array<string, mixed>
     */
    private function snapshot(PosBill $bill): array
    {
        return [
            'customer' => $bill->customer_name,
            'total' => $bill->total->toDecimal(),
            'discount' => $bill->discount->toDecimal(),
            'received' => $bill->received->toDecimal(),
            'paid_by' => $bill->paymentMethod?->label() ?? 'Cash',
            'lines' => $bill->lines->map(fn ($l) => [
                'product_id' => $l->company_product_id,
                'name' => $l->name,
                'quantity' => $l->quantity,
                'loose' => $l->loose_quantity,
                'unit_price' => $l->unit_price->toDecimal(),
                'line_total' => $l->line_total->toDecimal(),
            ])->values()->all(),
        ];
    }

    /**
     * What changed, in plain terms: added, removed, or changed from → to.
     *
     * @return array<int, array{kind: string, what: string, from: ?string, to: ?string}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        $say = fn (array $l) => trim(($l['quantity'] > 0 || $l['loose'] === 0 ? $l['quantity'] : '')
            .($l['loose'] > 0 ? ($l['quantity'] > 0 ? ' + ' : '').$l['loose'].' loose' : '')
            .' @ Rs. '.$l['unit_price']);

        $old = collect($before['lines'])->keyBy('product_id');
        $new = collect($after['lines'])->keyBy('product_id');

        foreach ($new as $id => $line) {
            if (! $old->has($id)) {
                $changes[] = ['kind' => 'added', 'what' => $line['name'], 'from' => null, 'to' => $say($line)];
            } elseif ($say($old[$id]) !== $say($line)) {
                $changes[] = ['kind' => 'changed', 'what' => $line['name'], 'from' => $say($old[$id]), 'to' => $say($line)];
            }
        }

        foreach ($old as $id => $line) {
            if (! $new->has($id)) {
                $changes[] = ['kind' => 'removed', 'what' => $line['name'], 'from' => $say($line), 'to' => null];
            }
        }

        foreach (['customer' => 'Customer', 'discount' => 'Discount', 'received' => 'Received', 'paid_by' => 'Paid by', 'total' => 'Total'] as $key => $label) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[] = [
                    'kind' => 'changed', 'what' => $label,
                    'from' => (string) ($before[$key] ?? '—'), 'to' => (string) ($after[$key] ?? '—'),
                ];
            }
        }

        return $changes;
    }
}
