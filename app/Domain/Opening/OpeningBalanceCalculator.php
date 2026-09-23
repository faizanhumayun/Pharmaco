<?php

namespace App\Domain\Opening;

use App\Enums\AccountType;
use App\Models\OpeningBalance;
use App\Support\Money;

class OpeningBalanceCalculator
{
    /** @param array<string, mixed> $input keyed by account code */
    public function fromInput(array $input): OpeningPosition
    {
        $amounts = [];
        $assets = Money::zero();
        $liabilities = Money::zero();
        $equity = Money::zero();

        foreach (OpeningField::all() as $field) {
            $amount = Money::of($input[$field->key()] ?? null);
            $amounts[$field->key()] = $amount;

            match ($field->account->type()) {
                AccountType::Asset => $assets = $assets->plus($amount),
                AccountType::Liability => $liabilities = $liabilities->plus($amount),
                AccountType::Equity => $equity = $equity->plus($amount),
                default => null,
            };
        }

        return new OpeningPosition($amounts, $assets, $liabilities, $equity);
    }

    public function fromRecord(OpeningBalance $opening): OpeningPosition
    {
        $opening->loadMissing('lines.account');

        $input = $opening->lines
            ->mapWithKeys(fn ($line) => [(string) $line->account->code => $line->amount->toDecimal()])
            ->all();

        return $this->fromInput($input);
    }
}
