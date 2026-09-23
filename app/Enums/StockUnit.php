<?php

namespace App\Enums;

/**
 * What one of something means to this business.
 *
 * A distributor sells the pack a company delivers: a carton, a box of strips.
 * A pharmacy breaks that pack open and sells what the patient needs — three
 * tablets from a strip of ten. The same product, counted two different ways,
 * so every screen that says "quantity" has to say whose quantity it means.
 */
enum StockUnit: string
{
    case Pack = 'pack';
    case Item = 'item';

    public function label(): string
    {
        return match ($this) {
            self::Pack => 'Full packs and cartons',
            self::Item => 'Loose items',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pack => 'Sold as the company delivers them — a whole pack, box or carton. Usual for a distributor.',
            self::Item => 'Sold as the customer needs them — single tablets, one bottle, one sachet. Usual for a pharmacy or medical store.',
        };
    }

    /** What one of them is called on screen. */
    public function one(): string
    {
        return match ($this) {
            self::Pack => 'pack',
            self::Item => 'item',
        };
    }

    public function many(): string
    {
        return match ($this) {
            self::Pack => 'packs',
            self::Item => 'items',
        };
    }

    /** The usual way to count for a kind of business, before anyone says otherwise. */
    public static function defaultFor(BusinessType $type): self
    {
        return $type === BusinessType::Pharmacy ? self::Item : self::Pack;
    }
}
