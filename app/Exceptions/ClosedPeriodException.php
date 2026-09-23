<?php

namespace App\Exceptions;

use Illuminate\Support\Carbon;

class ClosedPeriodException extends LedgerException
{
    public static function make(Carbon $date, Carbon $lockedThrough): self
    {
        return new self(sprintf(
            'Cannot post to %s: the business is closed through %s. '
            . 'Post an adjustment in the open period, or reopen the day.',
            $date->toDateString(),
            $lockedThrough->toDateString()
        ));
    }
}
