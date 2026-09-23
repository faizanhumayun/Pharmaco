<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Assets and expenses increase on debit; everything else on credit. */
    public function increasesOnDebit(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }

    /**
     * Multiplier turning a raw (debit − credit) figure into the account's
     * natural presentation. A payable of 3,200,000 sums to −3,200,000 in raw
     * terms; nobody wants to read it that way.
     */
    public function presentationSign(): int
    {
        return $this->increasesOnDebit() ? 1 : -1;
    }

    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }

    public function isProfitAndLoss(): bool
    {
        return ! $this->isBalanceSheet();
    }
}
