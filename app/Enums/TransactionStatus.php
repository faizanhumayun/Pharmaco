<?php

namespace App\Enums;

enum TransactionStatus: string
{
    /** Written but with no ledger effect. Excluded from every balance. */
    case Draft = 'draft';

    case Posted = 'posted';

    /** Still counted, and cancelled out by a mirror transaction that follows it. */
    case Reversed = 'reversed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * A reversed transaction still counts: its effect is cancelled by the
     * reversal's own entries, not by hiding the original. Removing it from the
     * balance would double-count the correction.
     */
    public function affectsBalances(): bool
    {
        return $this !== self::Draft;
    }

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::Posted => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
            self::Reversed => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }
}
