<?php

namespace App\Domain\Orders\Actions;

use App\Enums\DocumentStatus;
use App\Models\DailyEntry;
use App\Models\Order;
use App\Models\PurchaseLine;
use App\Models\User;
use App\Support\Money;
use RuntimeException;

/**
 * Puts a delivery's invoice into the day's entry as a purchase line.
 *
 * This is the only place receiving touches money, and it does not post: it
 * writes the invoice onto the daily entry, which posts it when the day is
 * posted, through the one writer everything else goes through. Receiving stays
 * a fact about goods; the daily entry stays the single door into the ledger.
 *
 * Re-recording a delivery updates the same line rather than adding a second,
 * which is what the order_id link is for.
 */
class RecordDeliveryPurchase
{
    public function __construct(private readonly \App\Domain\Daily\DayWriter $day) {}

    /** @param array<string, mixed> $data */
    public function handle(Order $order, array $data, User $by): ?PurchaseLine
    {
        $amount = Money::of($data['bill_amount'] ?? null);
        $paid = Money::of($data['paid'] ?? null);
        $invoice = trim((string) ($data['invoice_no'] ?? '')) ?: null;

        $existing = PurchaseLine::where('order_id', $order->id)->first();

        // Nothing about the bill has changed, so the day is left alone. Worth
        // checking first: amending a posted day reverses and re-posts all of
        // it, and doing that for an unchanged invoice is noise in the ledger.
        if ($existing !== null
            && $existing->invoice_no === $invoice
            && $existing->amount->equals($amount)
            && $existing->paid->equals($paid)) {
            return $existing;
        }

        // Nothing to record. An earlier line for this order is dropped, so
        // correcting a delivery down to nothing does not leave the invoice
        // behind on the day.
        if ($amount->isZero() && $paid->isZero() && $existing === null) {
            return null;
        }

        /*
         * Paying more than the bill is allowed, and means something.
         *
         * The posting service already splits what was handed over: up to the
         * bill buys the goods, and anything beyond it posts as a company
         * payment against that supplier's own account. If they are owed money
         * from earlier bills it comes off that; if they are not, the account
         * goes into debit, which is what an advance to a supplier is. Refusing
         * it here would have been stricter than the ledger it feeds.
         */

        /*
         * Through the day writer, which is what everything else adding to a
         * day goes through. A draft is saved, a posted day is amended — its
         * postings reversed and written again with the invoice on them — and a
         * closed day is refused. Before this, an invoice simply could not be
         * recorded once the day had posted, which on a counter that posts the
         * day at the first sale meant almost always.
         */
        $entry = $this->day->write($order->business, $order->business->today(), function (array $data) use ($order, $invoice, $amount, $paid) {
            $rows = array_values(array_filter(
                $data['purchases'] ?? [],
                fn ($row) => (int) ($row['order_id'] ?? 0) !== $order->id,
            ));

            if (! ($amount->isZero() && $paid->isZero())) {
                $rows[] = [
                    'company' => $order->company->name,
                    'order_id' => $order->id,
                    'invoice_no' => $invoice ?? '',
                    'amount' => $amount->toDecimal(),
                    'paid' => $paid->toDecimal(),
                ];
            }

            $data['purchases'] = $rows;

            return $data;
        }, $by);

        return $entry->purchaseLines()->where('order_id', $order->id)->first();
    }

    /**
     * The draft entry for the day the delivery arrived, created if the day has
     * not been started yet.
     */
    private function entryFor(Order $order, User $by): DailyEntry
    {
        $business = $order->business;
        $date = $business->today()->toDateString();

        $entry = DailyEntry::forBusiness($business)->where('business_date', $date)->first();

        if ($entry !== null && ! $entry->isEditable()) {
            throw new RuntimeException(
                "The daily entry for {$date} has already been posted, so this invoice cannot be added to it. "
                .'Record the delivery without the invoice, then amend that day.'
            );
        }

        return $entry ?? DailyEntry::create([
            'business_id' => $business->id,
            'business_date' => $date,
            'status' => DocumentStatus::Draft,
            'created_by' => $by->id,
        ]);
    }

    private function guardEditable(PurchaseLine $line): void
    {
        $entry = $line->dailyEntry;

        if ($entry === null) {
            return;
        }

        if ($line->order?->business?->isDayClosed($entry->business_date)) {
            throw new RuntimeException(
                'The day this invoice belongs to has been closed, so it can no longer be changed.'
            );
        }

        if (! $entry->isEditable()) {
            throw new RuntimeException(
                'This delivery\'s invoice is on a daily entry that has been posted, so the bill cannot be changed '
                .'here. Amend that day instead — the goods on this delivery can still be corrected.'
            );
        }
    }

    /**
     * The day's purchase header follows from its lines, exactly as it does when
     * the entry is saved from its own form.
     */
    private function refreshTotals(?DailyEntry $entry): void
    {
        if ($entry === null) {
            return;
        }

        $lines = $entry->purchaseLines()->get();

        if ($lines->isEmpty()) {
            return;
        }

        $entry->forceFill([
            'purchase_total' => Money::sum($lines->pluck('amount'))->toDecimal(),
            'purchase_paid' => Money::sum($lines->pluck('paid'))->toDecimal(),
        ])->save();
    }
}
