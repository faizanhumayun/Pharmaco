<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Draft = 'draft';
    case Committed = 'committed';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Awaiting review',
            self::Committed => 'Applied',
            self::Discarded => 'Discarded',
        };
    }

    /** A draft is the only state whose rows may still be edited or applied. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::Committed => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
            self::Discarded => 'bg-gray-100 text-gray-600 ring-gray-500/20',
        };
    }
}
