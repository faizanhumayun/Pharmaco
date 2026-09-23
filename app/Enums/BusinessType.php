<?php

namespace App\Enums;

enum BusinessType: string
{
    case Distributor = 'distributor';
    case Pharmacy = 'pharmacy';

    public function label(): string
    {
        return match ($this) {
            self::Distributor => 'Pharmaceutical Distributor',
            self::Pharmacy => 'Pharmacy',
        };
    }

    /** Pharmacy mode is surfaced in the UI as "Coming Soon" but is not implemented. */
    public function isAvailable(): bool
    {
        return $this === self::Distributor;
    }

    public static function available(): array
    {
        return array_filter(self::cases(), fn (self $t) => $t->isAvailable());
    }
}
