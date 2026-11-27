<?php

namespace App\Domain\Migration;

use App\Domain\Orders\Actions\RecordDeliveryPurchase;
use App\Models\Order;
use App\Models\PurchaseLine;
use App\Models\User;
use App\Support\Money;

/**
 * Receiving, with the invoice held back instead of written onto the day.
 *
 * Used only while old history is replayed ({@see OldPosHistory}): a day of the
 * old POS has several deliveries and a hundred bills, and writing each onto
 * the day as it happens would amend that day a hundred times. So the invoice
 * is kept here, and the replay writes the whole day once — the same purchase
 * line the delivery would have written, through the same day writer.
 */
class CollectedDeliveryPurchase extends RecordDeliveryPurchase
{
    /** @var list<array{company: string, order_id: int, invoice_no: string, amount: string, paid: string}> */
    public array $rows = [];

    public function handle(Order $order, array $data, User $by): ?PurchaseLine
    {
        $amount = Money::of($data['bill_amount'] ?? null);

        if (! $amount->isZero()) {
            $this->rows[] = [
                'company' => $order->company->name,
                'order_id' => $order->id,
                'invoice_no' => trim((string) ($data['invoice_no'] ?? '')),
                'amount' => $amount->toDecimal(),
                'paid' => Money::of($data['paid'] ?? null)->toDecimal(),
            ];
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function take(): array
    {
        [$rows, $this->rows] = [$this->rows, []];

        return $rows;
    }
}
