<?php

namespace App\Domain\Daily;

use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
use App\Models\DailyEntry;
use App\Support\Money;

/**
 * The "resulting position" panel.
 *
 * The operator sees the consequence of what they typed while they can still fix
 * it. A 300,000 typo is obvious in the preview and invisible in the form.
 */
class DailyEntryPreview
{
    public function __construct(private readonly BalanceService $balances) {}

    /** @return array<int, array{label: string, code: string, before: Money, delta: Money, after: Money, good: bool}> */
    public function rows(DailyEntry $entry): array
    {
        $business = $entry->business;
        $priorDay = $entry->business_date->copy()->subDay();

        // Cash taken from a pharmacy over and above its invoice. It is a
        // recovery, so it moves the same two balances a typed collection does
        // without ever having been part of the day's sales.
        $saleExcess = $entry->saleExcess();

        $deltas = [
            AccountCode::Cash->value => $entry->sale_cash
                ->plus($entry->collection_cash)
                ->plus($saleExcess)
                ->plus($entry->owner_capital)
                ->minus($entry->purchase_paid)
                ->minus($entry->company_payment_cash)
                ->minus($entry->expenses_cash)
                ->minus($entry->owner_drawing),

            AccountCode::MarketReceivables->value => $entry->sale_credit
                ->minus($entry->collection_cash)
                ->minus($saleExcess)
                ->minus($entry->sales_return)
                ->minus($entry->discount_allowed)
                ->minus($entry->bad_debt),

            AccountCode::CompanyPayables->value => $entry->purchasePending()
                ->minus($entry->purchaseExcess())
                ->minus($entry->company_payment_cash)
                ->minus($entry->purchase_return)
                ->minus($entry->discount_received),

            AccountCode::Stock->value => $entry->costAddedToStock()
                ->minus($entry->purchase_return)
                ->minus($entry->costOfGoodsSold()),
        ];

        $labels = [
            AccountCode::Cash->value => 'Cash in hand',
            AccountCode::MarketReceivables->value => 'Market receivables',
            AccountCode::CompanyPayables->value => 'Company payables',
            AccountCode::Stock->value => 'Stock (estimated)',
        ];

        // Lower payables is good; higher receivables is not. Encoding that here
        // keeps the view from having to know any accounting.
        $lowerIsBetter = [AccountCode::CompanyPayables->value, AccountCode::MarketReceivables->value];

        $rows = [];

        foreach ($deltas as $code => $delta) {
            $before = $this->balances->asAt($business, $code, $priorDay);

            $rows[] = [
                'label' => $labels[$code],
                'code' => $code,
                'before' => $before,
                'delta' => $delta,
                'after' => $before->plus($delta),
                'good' => in_array($code, $lowerIsBetter, true)
                    ? ! $delta->isPositive()
                    : ! $delta->isNegative(),
            ];
        }

        return $rows;
    }

    public function netPositionBefore(DailyEntry $entry): Money
    {
        return $this->balances
            ->position($entry->business, $entry->business_date->copy()->subDay())
            ->netPosition();
    }

    public function netPositionAfter(DailyEntry $entry): Money
    {
        // Profit is what moves the position; the rest is money changing form.
        return $this->netPositionBefore($entry)->plus($entry->netProfit());
    }
}
