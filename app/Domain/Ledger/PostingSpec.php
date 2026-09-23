<?php

namespace App\Domain\Ledger;

use App\Enums\TransactionType;
use App\Models\Business;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Everything the poster needs to write one balanced transaction. */
final class PostingSpec
{
    /** @param array<int, PostingLine> $lines */
    public function __construct(
        public readonly Business $business,
        public readonly Carbon $date,
        public readonly TransactionType $type,
        public readonly array $lines,
        public readonly User $createdBy,
        public readonly ?string $narration = null,
        public readonly ?string $reference = null,
        public readonly ?Model $source = null,
        public readonly ?string $reason = null,
        public readonly ?Carbon $originalDate = null,
    ) {}

    public function totalDebits(): Money
    {
        return Money::sum(array_map(fn (PostingLine $l) => $l->debit, $this->lines));
    }

    public function totalCredits(): Money
    {
        return Money::sum(array_map(fn (PostingLine $l) => $l->credit, $this->lines));
    }

    /** The headline figure shown in listings — one side of a balanced entry. */
    public function headlineAmount(): Money
    {
        return $this->totalDebits();
    }

    public function withLines(array $lines): self
    {
        return new self(
            $this->business, $this->date, $this->type, $lines, $this->createdBy,
            $this->narration, $this->reference, $this->source, $this->reason, $this->originalDate,
        );
    }
}
