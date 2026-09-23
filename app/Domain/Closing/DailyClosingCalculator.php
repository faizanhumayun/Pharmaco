<?php

namespace App\Domain\Closing;

use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Models\Business;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes a day's closing figures from the ledger, and only from the ledger.
 *
 * Nothing here reads a stored balance, which is what makes the rebuild command
 * able to reproduce any historical day exactly.
 */
class DailyClosingCalculator
{
    public function __construct(private readonly BalanceService $balances) {}

    /** @return array<string, Money> */
    public function compute(Business $business, Carbon $date): array
    {
        $prior = $date->copy()->subDay();

        $open = [
            'stock' => $this->balances->asAt($business, AccountCode::Stock, $prior),
            'cash' => $this->balances->asAt($business, AccountCode::Cash, $prior),
            'receivable' => $this->balances->asAt($business, AccountCode::MarketReceivables, $prior),
            'payable' => $this->balances->asAt($business, AccountCode::CompanyPayables, $prior),
        ];

        $close = [
            'stock' => $this->balances->asAt($business, AccountCode::Stock, $date),
            'cash' => $this->balances->asAt($business, AccountCode::Cash, $date),
            'receivable' => $this->balances->asAt($business, AccountCode::MarketReceivables, $date),
            'payable' => $this->balances->asAt($business, AccountCode::CompanyPayables, $date),
        ];

        $sales = $this->balances->movement($business, AccountCode::Sales, $date, $date)
            ->minus($this->balances->movement($business, AccountCode::SalesReturns, $date, $date));
        $cogs = $this->balances->movement($business, AccountCode::CostOfGoodsSold, $date, $date);
        $expenses = $this->balances->movement($business, AccountCode::OperatingExpenses, $date, $date);
        $variance = $this->balances->movement($business, AccountCode::StockVariance, $date, $date);
        $badDebts = $this->balances->movement($business, AccountCode::BadDebts, $date, $date);

        $grossProfit = $sales->minus($cogs);

        return [
            'opening_stock' => $open['stock'],
            'opening_cash' => $open['cash'],
            'opening_receivable' => $open['receivable'],
            'opening_payable' => $open['payable'],

            // Total at cost — what stock gained.
            'purchases' => $this->movementByType($business, $date, [
                TransactionType::PurchaseCredit, TransactionType::PurchaseCash,
            ]),
            // Paid at the time of buying: cash out, but not a payable movement.
            'purchases_paid' => $this->movementByType($business, $date, [TransactionType::PurchaseCash]),
            // Left owing: what today's buying added to the companies.
            'purchases_on_account' => $this->movementByType($business, $date, [TransactionType::PurchaseCredit]),
            'sales' => $sales,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'expenses' => $expenses,
            // Two labelled figures, never one number called "profit".
            'net_profit' => $grossProfit->minus($expenses)->minus($variance)->minus($badDebts),

            'collections' => $this->movementByType($business, $date, [TransactionType::Collection]),
            'company_payments' => $this->movementByType($business, $date, [TransactionType::CompanyPayment]),
            'credit_sales' => $this->movementByType($business, $date, [TransactionType::SaleCredit]),
            'cash_sales' => $this->movementByType($business, $date, [TransactionType::SaleCash]),

            'closing_stock' => $close['stock'],
            'closing_cash' => $close['cash'],
            'closing_receivable' => $close['receivable'],
            'closing_payable' => $close['payable'],

            // The figure that matters as much as profit: a distributor can be
            // profitable every day while the money sits in a pharmacy's ledger.
            'receivable_delta' => $close['receivable']->minus($open['receivable']),
            'payable_delta' => $close['payable']->minus($open['payable']),

            'net_position' => $this->balances->position($business, $date)->netPosition(),
        ];
    }

    /**
     * Cash that a correction to the opening balance put in (or took out) on
     * this day. Shown as its own line on the closing screen; deliberately not
     * part of compute(), whose every key is a stored column.
     */
    public function openingCashCorrection(Business $business, Carbon $date): Money
    {
        $opening = $business->openingBalance;

        if ($opening === null) {
            return Money::zero();
        }

        $total = Money::zero();

        foreach ($opening->corrections()->whereDate('business_date', $date->toDateString())->with('lines.account')->get() as $correction) {
            foreach ($correction->lines as $line) {
                if ($line->account->code === AccountCode::Cash->value) {
                    $total = $total->plus($line->debit)->minus($line->credit);
                }
            }
        }

        return $total;
    }

    /**
     * The day's net flow of these kinds of transaction.
     *
     * A reversal cancels its original, so it comes off here too. Amending an
     * open day reverses and reposts on the same date, which nets back to the
     * figures as they stand; without this, every earlier version of the day was
     * added in again. When the original's day was already closed, the reversal
     * lands on the first open day and that day carries the reduction — the
     * same place the balances take it.
     *
     * @param array<int, TransactionType> $types
     */
    private function movementByType(Business $business, Carbon $date, array $types): Money
    {
        $values = array_column($types, 'value');

        $onDay = fn () => DB::table('transactions')
            ->where('transactions.business_id', $business->id)
            ->whereDate('transactions.business_date', $date->toDateString())
            ->where('transactions.status', '!=', 'draft');

        $posted = $onDay()->whereIn('transactions.type', $values)->sum('transactions.amount');

        $reversed = $onDay()
            ->where('transactions.type', TransactionType::Reversal->value)
            ->join('transactions as original', 'original.id', '=', 'transactions.reversal_of_id')
            ->whereIn('original.type', $values)
            ->sum('transactions.amount');

        return Money::of($posted ?? 0)->minus(Money::of($reversed ?? 0));
    }
}
