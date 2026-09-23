<?php

namespace App\Exceptions;

use App\Support\Money;

class UnbalancedTransactionException extends LedgerException
{
    public static function make(Money $debits, Money $credits): self
    {
        return new self(sprintf(
            'Transaction does not balance: debits %s, credits %s, difference %s.',
            $debits->format(),
            $credits->format(),
            $debits->minus($credits)->format()
        ));
    }
}
