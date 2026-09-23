<?php

namespace App\Domain\Opening;

use App\Enums\AccountCode;

/**
 * The figures the App Owner is asked for at cutover.
 *
 * Four of them are the ones the business already thinks in. The rest default to
 * zero, so the simple case stays simple — but they must exist, or whatever is
 * missing gets absorbed silently by the balancing figure and quietly distorts
 * the position on every screen thereafter.
 */
final class OpeningField
{
    private function __construct(
        public readonly AccountCode $account,
        public readonly string $label,
        public readonly bool $required,
        public readonly ?string $hint = null,
    ) {}

    /** @return array<int, self> */
    public static function all(): array
    {
        return [
            new self(AccountCode::Stock, 'Opening stock value', true,
                'At cost, not at sale price. Counted physically on the opening date.'),
            new self(AccountCode::Cash, 'Cash in hand', true,
                'Physically counted on the opening date.'),
            new self(AccountCode::MarketReceivables, 'Market receivables', true,
                'Agreed against customer statements, not estimated.'),
            new self(AccountCode::CompanyPayables, 'Company payables', true,
                'Agreed against company statements.'),
            new self(AccountCode::AdvancesToCompanies, 'Advances paid to companies', false,
                'Prepayments. A negative payable is an asset, not a payable.'),
            new self(AccountCode::CustomerAdvances, 'Customer advances held', false,
                'Overpayments received. Never netted against receivables.'),
            new self(AccountCode::FixedAssets, 'Fixed assets', false,
                'Vehicles, equipment, deposits. Often the missing piece when the position looks wrong.'),
            new self(AccountCode::OwnerCapital, 'Owner capital already invested', false,
                'If known. Reduces the unexplained balancing figure.'),
        ];
    }

    public function key(): string
    {
        return $this->account->value;
    }
}
