<?php

namespace App\Enums;

/**
 * The chart of accounts, as constants.
 *
 * The posting service references accounts through this enum and never by name
 * or id: ids differ per business and names are editable, so either would make
 * postings quietly wrong in a way no test would catch.
 */
enum AccountCode: string
{
    // Assets
    case Cash = '1000';
    case Bank = '1010';
    case MarketReceivables = '1100';
    case Stock = '1200';
    case AdvancesToCompanies = '1300';
    case FixedAssets = '1400';

    // Liabilities
    case CompanyPayables = '2000';
    case CustomerAdvances = '2100';
    case AccruedExpenses = '2200';

    // Equity
    case OwnerCapital = '3000';
    case OwnerDrawings = '3100';
    case OpeningBalanceEquity = '3900';

    // Income
    case Sales = '4000';
    case SalesReturns = '4100';
    case DiscountReceived = '4200';

    // Expenses
    case CostOfGoodsSold = '5000';
    case StockVariance = '5100';
    case DiscountAllowed = '5200';
    case BadDebts = '5300';
    case OperatingExpenses = '6000';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash in Hand',
            self::Bank => 'Bank',
            self::MarketReceivables => 'Market Receivables',
            self::Stock => 'Stock / Inventory',
            self::AdvancesToCompanies => 'Advances to Companies',
            self::FixedAssets => 'Fixed Assets',
            self::CompanyPayables => 'Company Payables',
            self::CustomerAdvances => 'Customer Advances',
            self::AccruedExpenses => 'Accrued Expenses',
            self::OwnerCapital => 'Owner Capital',
            self::OwnerDrawings => 'Owner Drawings',
            self::OpeningBalanceEquity => 'Opening Balance Equity',
            self::Sales => 'Sales',
            self::SalesReturns => 'Sales Returns',
            self::DiscountReceived => 'Discount Received',
            self::CostOfGoodsSold => 'Cost of Goods Sold',
            self::StockVariance => 'Stock Variance',
            self::DiscountAllowed => 'Discount Allowed',
            self::BadDebts => 'Bad Debts',
            self::OperatingExpenses => 'Operating Expenses',
        };
    }

    public function type(): AccountType
    {
        return match ($this) {
            self::Cash, self::Bank, self::MarketReceivables, self::Stock,
            self::AdvancesToCompanies, self::FixedAssets => AccountType::Asset,

            self::CompanyPayables, self::CustomerAdvances,
            self::AccruedExpenses => AccountType::Liability,

            self::OwnerCapital, self::OwnerDrawings,
            self::OpeningBalanceEquity => AccountType::Equity,

            self::Sales, self::SalesReturns, self::DiscountReceived => AccountType::Income,

            self::CostOfGoodsSold, self::StockVariance, self::DiscountAllowed,
            self::BadDebts, self::OperatingExpenses => AccountType::Expense,
        };
    }

    /**
     * A contra account sits inside one type but carries the opposite balance:
     * drawings reduce equity, returns reduce income. Both are read as positive
     * amounts of their own thing, so presentation flips their sign.
     */
    public function isContra(): bool
    {
        return in_array($this, [self::OwnerDrawings, self::SalesReturns], true);
    }

    /** Control accounts gain children in a later phase and stop being posted to directly. */
    public function isControl(): bool
    {
        return in_array($this, [self::MarketReceivables, self::CompanyPayables], true);
    }

    /**
     * Bank is seeded so that enabling it later is a settings change rather than
     * a migration, but it is not postable: the business runs entirely on cash
     * (decision D4). Nothing may post to it until that decision changes.
     */
    public function isPostable(): bool
    {
        return $this !== self::Bank;
    }

    public function description(): ?string
    {
        return match ($this) {
            self::Cash => 'Physical cash. Counted and reconciled at every daily closing.',
            self::Bank => 'Not in use — this business operates on cash. Seeded so it can be enabled without a migration.',
            self::MarketReceivables => 'Money owed by the market. Gains per-customer children in a later phase.',
            self::Stock => 'Inventory at cost. Derived, not counted — verify it periodically.',
            self::CompanyPayables => 'Owed to pharmaceutical companies. Gains per-company children in Phase 9.',
            self::CustomerAdvances => 'Overpayments held. Never netted silently against receivables.',
            self::AdvancesToCompanies => 'Prepayments made. A negative payable is an asset, not a payable.',
            self::OpeningBalanceEquity => 'The balancing figure at cutover. A large balance here is an exception to explain, never a plug.',
            self::OwnerDrawings => 'Cash taken by the owner. Without this, cash never reconciles.',
            self::StockVariance => 'Damage, expiry, theft and count differences. Bounds the drift in stock value.',
            self::BadDebts => 'Written-off receivables. Receivable value is not recoverable value.',
            default => null,
        };
    }

    /** @return array<int, self> */
    public static function postable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c->isPostable()));
    }
}
