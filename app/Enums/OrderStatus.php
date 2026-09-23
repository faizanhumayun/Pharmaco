<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Received => 'Received',
        };
    }

    /** A draft is still being written; anything further is a record. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Sent, and the delivery has not been ticked off yet. */
    public function isOutstanding(): bool
    {
        return $this === self::Sent;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            // Sent is a waiting state, not a finished one, so it does not get
            // the same green as an order that has actually arrived.
            self::Sent => 'bg-sky-50 text-sky-800 ring-sky-600/20',
            self::Received => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        };
    }
}
