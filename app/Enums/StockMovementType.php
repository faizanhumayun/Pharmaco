<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';
    case Delivery = 'delivery';
    case Adjustment = 'adjustment';
    /** Written by the point of sale, when there is one. */
    case Sale = 'sale';
    /** Goods a customer brought back to the counter. */
    case SaleReturn = 'sale_return';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::Delivery => 'Delivery',
            self::Adjustment => 'Adjustment',
            self::Sale => 'Sale',
            self::SaleReturn => 'Customer return',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Opening => 'bg-gray-100 text-gray-600 ring-gray-500/20',
            self::Delivery => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
            self::Adjustment => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::Sale => 'bg-sky-50 text-sky-800 ring-sky-600/20',
            self::SaleReturn => 'bg-violet-50 text-violet-800 ring-violet-600/20',
        };
    }
}
