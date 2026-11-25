<?php

namespace App\Exceptions;

use Illuminate\Support\Carbon;

class ClosedPeriodException extends LedgerException
{
    public static function make(Carbon $date, Carbon $lockedThrough): self
    {
        return new self(sprintf(
            'Cannot post to %s. The business is closed up to %s. '
            . 'Post an adjustment in the open period, or reopen the day.',
            $date->toDateString(),
            $lockedThrough->toDateString()
        ));
    }
}
