<?php

namespace App\Domain\Ledger;

use App\Enums\AccountType;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The management position at a point in time.
 *
 * Not a balance sheet, and labelled that way everywhere it is shown: it
 * excludes depreciation, tax positions, and anything the owner did not declare
 * at cutover.
 */
final class PositionSummary
{
    /** @param Collection<string, Money> $balances keyed by account code */
    public function __construct(
        public readonly Carbon $asAt,
        public readonly Collection $balances,
        public readonly Money $assets,
        public readonly Money $liabilities,
        public readonly Money $equity,
        public readonly Money $income,
        public readonly Money $expenses,
    ) {}

    public function netPosition(): Money
    {
        return $this->assets->minus($this->liabilities);
    }

    public function retainedProfit(): Money
    {
        return $this->income->minus($this->expenses);
    }

    /**
     * The second, independent derivation of net position.
     *
     * Assets − liabilities must equal equity plus retained profit. Two paths to
     * the same number is a free, continuous integrity test — if they disagree,
     * something is broken and the dashboard should say so rather than pick one.
     */
    public function equityDerivation(): Money
    {
        return $this->equity->plus($this->retainedProfit());
    }

    public function isConsistent(): bool
    {
        return $this->netPosition()->equals($this->equityDerivation());
    }

    public function discrepancy(): Money
    {
        return $this->netPosition()->minus($this->equityDerivation());
    }

    public function balance(string $code): Money
    {
        return $this->balances->get($code, Money::zero());
    }
}
