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

        ['sales' => $sales, 'cogs' => $cogs, 'gross_profit' => $grossProfit, 'expenses' => $expenses, 'net_profit' => $netProfit]
            = $this->profitBetween($business, $date, $date);

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
            'net_profit' => $netProfit,
            // Stock found short (positive, a loss) or over (negative, a gain)
            // on counts and audits approved today, at cost. Already inside
            // net profit; said on its own so the day explains itself.
            'stock_variance' => $this->balances->movement($business, AccountCode::StockVariance, $date, $date),

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
     * Sales, what they cost, expenses and the profit left, over a run of days
     * (one day, or the month so far). The one formula for net profit: gross
     * profit less expenses, stock written off and debts written off.
     *
     * @return array{sales: Money, cogs: Money, gross_profit: Money, expenses: Money, written_off: Money, net_profit: Money}
     */
    public function profitBetween(Business $business, Carbon $from, Carbon $to): array
    {
        $sales = $this->balances->movement($business, AccountCode::Sales, $from, $to)
            ->minus($this->balances->movement($business, AccountCode::SalesReturns, $from, $to));
        $cogs = $this->balances->movement($business, AccountCode::CostOfGoodsSold, $from, $to);
        $expenses = $this->balances->movement($business, AccountCode::OperatingExpenses, $from, $to);
        $writtenOff = $this->balances->movement($business, AccountCode::StockVariance, $from, $to)
            ->plus($this->balances->movement($business, AccountCode::BadDebts, $from, $to));
        $grossProfit = $sales->minus($cogs);

        return [
            'sales' => $sales,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'expenses' => $expenses,
            'written_off' => $writtenOff,
            'net_profit' => $grossProfit->minus($expenses)->minus($writtenOff),
        ];
    }

    /**
     * Cash the owner already had before the day's own trading: the cash at
     * the start of the day and any correction to the opening balance made on
     * it. Everything else that moved cash on the day went through the drawer.
     */
    public function carriedCash(Business $business, Carbon $date): Money
    {
        return $this->balances->asAt($business, AccountCode::Cash, $date->copy()->subDay())
            ->plus($this->openingCashCorrection($business, $date));
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
