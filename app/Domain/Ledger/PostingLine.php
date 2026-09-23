<?php

namespace App\Domain\Ledger;

use App\Enums\AccountCode;
use App\Support\Money;
use InvalidArgumentException;

/** One side of a posting. A line is a debit or a credit, never both. */
final class PostingLine
{
    private function __construct(
        public readonly AccountCode|string $account,
        public readonly Money $debit,
        public readonly Money $credit,
        public readonly ?string $memo = null,
    ) {
        if ($debit.'' !== '0.00' && $credit.'' !== '0.00') {
            throw new InvalidArgumentException('A ledger line cannot be both a debit and a credit.');
        }

        if ($debit->isNegative() || $credit->isNegative()) {
            throw new InvalidArgumentException('A ledger line cannot be negative. Post it to the other side instead.');
        }

        if ($debit->isZero() && $credit->isZero()) {
            throw new InvalidArgumentException('A ledger line cannot be zero.');
        }
    }

    public static function debit(AccountCode|string $account, Money|string|int|float $amount, ?string $memo = null): self
    {
        return new self($account, Money::of($amount), Money::zero(), $memo);
    }

    public static function credit(AccountCode|string $account, Money|string|int|float $amount, ?string $memo = null): self
    {
        return new self($account, Money::zero(), Money::of($amount), $memo);
    }

    public function accountCode(): string
    {
        return $this->account instanceof AccountCode ? $this->account->value : $this->account;
    }
}
