<?php

namespace App\Enums;

enum OpeningBalanceStatus: string
{
    /** Values editable by the App Owner. No ledger effect yet. */
    case Draft = 'draft';

    /** The OPENING transaction is posted. Read-only from here on. */
    case Finalized = 'finalized';

    /** The business has traded since. Part of history now. */
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Finalized => 'Finalized',
            self::Locked => 'Locked',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isPosted(): bool
    {
        return $this !== self::Draft;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::Finalized => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
            self::Locked => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Values can still be corrected. Nothing has been posted.',
            self::Finalized => 'Posted to the ledger. Corrections require an adjustment.',
            self::Locked => 'The business has traded since. This is history.',
        };
    }
}
