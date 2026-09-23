<?php

namespace App\Exceptions;

class ImmutableRecordException extends LedgerException
{
    public static function forTransaction(string $reason = 'has been posted'): self
    {
        return new self(
            "This transaction {$reason} and cannot be changed. "
            . 'Corrections are made by reversing it and posting a replacement.'
        );
    }

    /** @param string $what e.g. "daily entry", "opening balance" */
    public static function forDocument(string $what, array $changed = []): self
    {
        $detail = $changed === [] ? '' : ' (attempted to change: ' . implode(', ', $changed) . ')';

        return new self(
            "This {$what} has been posted{$detail} and cannot be changed. "
            . 'Corrections are made by reversing it and posting a replacement.'
        );
    }

    public static function forLedgerEntry(): self
    {
        return new self(
            'Ledger entries are append-only. They are never updated and never deleted.'
        );
    }
}
