<?php

namespace App\Enums;

/**
 * What a bill is printed on.
 *
 * A distributor hands over a document: the pharmacy files it, matches it
 * against its own purchases and pays against it, so it wants a full sheet with
 * room for a heading, an address and a signature. A retail counter hands over
 * a slip from a roll on the desk, 80mm wide, and the customer puts it in their
 * pocket.
 *
 * The same bill, printed for two different purposes — so this is a choice per
 * business with a sensible default, not something hard-wired to the till.
 */
enum ReceiptFormat: string
{
    /**
     * Whether the net bill is offered at all.
     *
     * Off for now, and the reason is worth keeping: a net bill should be the
     * product's own retail price less the trade discount, and retail price is
     * not yet something the catalogue reliably carries. Grossing up whatever
     * was charged is a workable stand-in on a screen, but it is not the bill
     * the trade expects, so it is not shown to customers until the retail
     * figure is there to build it from.
     *
     * Turning this back on restores the switch at the counter and on the
     * receipt, and nothing else has to change. While it is off the choice is
     * ignored everywhere, including any value a till has already remembered.
     */
    public const NET_BILL_AVAILABLE = false;

    /**
     * The trade discount a net bill is quoted with.
     *
     * Presentation only: the rates on that bill are grossed up by this so the
     * discount lands back on what is actually charged. Nothing is taken off
     * anything — move this figure and only the printed rates move with it.
     */
    public const TRADE_DISCOUNT_PERCENT = '15';

    case A4 = 'a4';
    case Thermal = 'thermal';

    public function label(): string
    {
        return match ($this) {
            self::A4 => 'A4 invoice',
            self::Thermal => 'Thermal slip',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::A4 => 'A4',
            self::Thermal => '80mm',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::A4 => 'A full sheet with a heading, the customer\'s details and room to sign. Usual for a distributor, whose bill is filed and paid against.',
            self::Thermal => 'An 80mm slip from a till roll, printed and handed over. Usual for a pharmacy or medical store.',
        };
    }

    /** The paper the browser should be told to use. */
    public function pageSize(): string
    {
        return match ($this) {
            self::A4 => 'A4 portrait',
            // Height grows with the bill rather than cutting it at a page.
            self::Thermal => '80mm auto',
        };
    }

    /** Which slip this one is not, for the switch on screen. */
    public function other(): self
    {
        return $this === self::A4 ? self::Thermal : self::A4;
    }

    /** The usual paper for a kind of business, before anyone says otherwise. */
    public static function defaultFor(BusinessType $type): self
    {
        return $type === BusinessType::Pharmacy ? self::Thermal : self::A4;
    }
}
