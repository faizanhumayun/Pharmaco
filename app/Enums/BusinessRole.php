<?php

namespace App\Enums;

/**
 * A person's role inside one business.
 *
 * What a role may do lives in Permission::forRoles(), never here and never in a
 * controller — so adding a role is a case below plus its permission bundle.
 */
enum BusinessRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
    case OrderBooker = 'order_booker';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Business Owner',
            self::Admin => 'Admin',
            self::Operator => 'Entry Operator',
            self::OrderBooker => 'Order Booker',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full view of the business position; can close days, write off bad debt and record owner drawings.',
            self::Admin => 'Runs the business for the owner: enters and closes days, manages companies, products and orders, and sees the position. Cannot manage staff, reopen a closed day, or touch owner money.',
            self::Operator => 'Enters day-to-day activity. Cannot close days or see the overall business position.',
            self::OrderBooker => 'Books orders from pharmacies. Sees price lists only — no daily entries, no balances, no reports.',
        };
    }
}
