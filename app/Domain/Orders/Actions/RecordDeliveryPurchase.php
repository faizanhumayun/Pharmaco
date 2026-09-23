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
    /** @param array<string, mixed> $data */
    public function handle(Order $order, array $data, User $by): ?PurchaseLine
    {
        $amount = Money::of($data['bill_amount'] ?? null);
        $paid = Money::of($data['paid'] ?? null);
        $invoice = trim((string) ($data['invoice_no'] ?? '')) ?: null;

        $existing = PurchaseLine::where('order_id', $order->id)->first();

        // Nothing to record. An earlier line for this order is removed, so
        // correcting a delivery down to nothing does not leave the invoice
        // behind on the day.
        if ($amount->isZero() && $paid->isZero()) {
            if ($existing !== null) {
                $this->guardEditable($existing);
                $entry = $existing->dailyEntry;
                $existing->delete();
                $this->refreshTotals($entry);
            }

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

        if ($existing !== null) {
            // Correcting the goods but not the bill. No money is moving, so the
            // state of the day is beside the point and the invoice is left
            // exactly as it is — which is what lets a delivery on a posted but
            // still-open day have its quantities fixed.
            if ($existing->invoice_no === $invoice
                && $existing->amount->equals($amount)
                && $existing->paid->equals($paid)) {
                return $existing;
            }

            $this->guardEditable($existing);

            $existing->forceFill([
                'company_id' => $order->company_id,
                'company_name' => $order->company->name,
                'invoice_no' => $invoice,
                'amount' => $amount,
                'paid' => $paid,
            ])->save();

            $this->refreshTotals($existing->dailyEntry);

            return $existing->fresh();
        }

        $entry = $this->entryFor($order, $by);

        $line = $entry->purchaseLines()->create([
            'company_id' => $order->company_id,
            'company_name' => $order->company->name,
            'order_id' => $order->id,
            'invoice_no' => $invoice,
            'amount' => $amount->toDecimal(),
            'paid' => $paid->toDecimal(),
        ]);

        $this->refreshTotals($entry);

        return $line;
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
