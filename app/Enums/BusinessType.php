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

    /**
     * Both kinds can be set up. What differs between them is how stock is
     * counted — whole packs for a distributor, loose items for a pharmacy —
     * which each business says for itself (see StockUnit); the books, the day
     * and the counter work the same either way.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    public static function available(): array
    {
        return array_filter(self::cases(), fn (self $t) => $t->isAvailable());
    }
}
