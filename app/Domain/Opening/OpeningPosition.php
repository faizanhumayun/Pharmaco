<?php

namespace App\Domain\Opening;

use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Support\Money;

/** The arithmetic of a cutover position, before anything is committed. */
final class OpeningPosition
{
    /** @param array<string, Money> $amounts keyed by account code */
    public function __construct(
        public readonly array $amounts,
        public readonly Money $assets,
        public readonly Money $liabilities,
        public readonly Money $equity,
    ) {}

    public function netPosition(): Money
    {
        return $this->assets->minus($this->liabilities);
    }

    /**
     * What has to go to Opening Balance Equity for the entry to balance.
     *
     * Positive means it lands on the credit side (assets exceed what has been
     * accounted for); negative means the debit side.
     */
    public function balancingFigure(): Money
    {
        return $this->netPosition()->minus($this->equity);
    }

    public function isNegative(): bool
    {
        return $this->netPosition()->isNegative();
    }

    /**
     * How large the unexplained figure is relative to the assets declared.
     *
     * Returns null when there are no assets to compare against.
     */
    public function balancingFigureRatio(): ?float
    {
        if ($this->assets->isZero()) {
            return null;
        }

        return (float) $this->balancingFigure()->absolute()->toDecimal()
            / (float) $this->assets->toDecimal();
    }

    /**
     * Whether the balancing figure is large enough to demand an explanation.
     *
     * The threshold turns the most dangerous number in the system into the most
     * visible one: past it, the review screen refuses to move on until somebody
     * writes down why.
     */
    public function needsExplanation(float $threshold = 0.05): bool
    {
        $ratio = $this->balancingFigureRatio();

        return $ratio !== null && $ratio > $threshold;
    }

    public function isEmpty(): bool
    {
        return $this->assets->isZero()
            && $this->liabilities->isZero()
            && $this->equity->isZero();
    }

    /**
     * The OPENING journal, balancing figure included.
     *
     * @return array<int, PostingLine>
     */
    public function toPostingLines(): array
    {
        $lines = [];

        foreach ($this->amounts as $code => $amount) {
            if ($amount->isZero()) {
                continue;
            }

            // Account codes are numeric strings, and PHP stores them as integer
            // array keys. Cast back before handing one to a string-backed enum.
            $account = AccountCode::from((string) $code);

            $lines[] = $account->type()->increasesOnDebit()
                ? PostingLine::debit($account, $amount, 'Opening position')
                : PostingLine::credit($account, $amount, 'Opening position');
        }

        $balancer = $this->balancingFigure();

        if (! $balancer->isZero()) {
            $lines[] = $balancer->isPositive()
                ? PostingLine::credit(AccountCode::OpeningBalanceEquity, $balancer, 'Opening balance')
                : PostingLine::debit(AccountCode::OpeningBalanceEquity, $balancer->absolute(), 'Opening balance');
        }

        return $lines;
    }
}
