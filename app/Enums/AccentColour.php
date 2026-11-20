<?php

namespace App\Enums;

/**
 * The colours a business may choose for its own screens.
 *
 * A fixed set, not a colour picker: red and amber already mean "overdue" and
 * "warning", blue means "receiving stock", and a colour picked freely can be
 * unreadable as a button or a link. Each of these has a full scale in
 * resources/css/pharmaco/tokens.css under .accent-{value}, which is the only
 * place the colours themselves are written.
 */
enum AccentColour: string
{
    case Teal = 'teal';
    case Violet = 'violet';
    case Forest = 'forest';
    case Wine = 'wine';
    case Copper = 'copper';
    case Graphite = 'graphite';

    public function label(): string
    {
        return match ($this) {
            self::Teal => 'Teal',
            self::Violet => 'Violet',
            self::Forest => 'Forest green',
            self::Wine => 'Wine',
            self::Copper => 'Copper',
            self::Graphite => 'Graphite',
        };
    }

    /** What a business of this kind uses until it chooses otherwise. */
    public static function defaultFor(BusinessType $type): self
    {
        return $type === BusinessType::Distributor ? self::Violet : self::Teal;
    }
}
