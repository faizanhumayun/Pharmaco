<?php

namespace App\Exceptions;

use App\Models\Account;

class AccountNotPostableException extends LedgerException
{
    public static function make(Account $account, string $why): self
    {
        return new self("Cannot post to {$account->code} {$account->name}: {$why}.");
    }
}
