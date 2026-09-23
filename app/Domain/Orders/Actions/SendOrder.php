<?php

namespace App\Domain\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use RuntimeException;

/**
 * Marks an order form as sent.
 *
 * This is a record of what left the building, not an instruction to anything —
 * no message is transmitted and no figure moves. It fixes the document so the
 * copy the company holds and the copy here cannot drift apart.
 */
class SendOrder
{
    public function handle(Order $order, ?User $by = null): Order
    {
        if (! $order->isEditable()) {
            throw new RuntimeException('This order has already been sent.');
        }

        if ($order->lines()->count() === 0) {
            throw new RuntimeException('An empty order form cannot be sent.');
        }

        $order->forceFill([
            'status' => OrderStatus::Sent,
            'sent_at' => now(),
        ])->save();

        activity()
            ->performedOn($order)
            ->causedBy($by)
            ->withProperties([
                'reference' => $order->reference,
                'company' => $order->company->name,
                'lines' => $order->lines()->count(),
                'total' => $order->loadLines()->total()->toDecimal(),
            ])
            ->event('order.sent')
            ->log('Order form sent');

        return $order->fresh();
    }
}
