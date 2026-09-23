<?php

namespace App\Enums;

enum BusinessStatus: string
{
    /** Created, but no finalized opening balance yet — cannot record transactions. */
    case Setup = 'setup';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Setup => 'Awaiting opening balance',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }

    /** Tailwind classes for the status pill. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Setup => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::Active => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
            self::Suspended => 'bg-red-50 text-red-800 ring-red-600/20',
            self::Archived => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /** Whether members may record financial activity. */
    public function allowsTransactions(): bool
    {
        return $this === self::Active;
    }
}
