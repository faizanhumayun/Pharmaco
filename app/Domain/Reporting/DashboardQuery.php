<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\BalanceService;
use App\Domain\Stock\StockConfidence;
use App\Enums\AccountCode;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Support\Money;
use Illuminate\Support\Carbon;

class DashboardQuery
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly StockConfidence $confidence,
        private readonly AlertEngine $alerts,
    ) {}

    public function build(Business $business, ?Carbon $date = null): array
    {
        $date ??= $business->today();
        $position = $this->balances->position($business, $date);
        $previous = $date->copy()->subDay();

        $movement = fn (AccountCode $code) => $this->balances->movement($business, $code, $date, $date);

        $sales = $movement(AccountCode::Sales)->minus($movement(AccountCode::SalesReturns));
        $cogs = $movement(AccountCode::CostOfGoodsSold);
        $expenses = $movement(AccountCode::OperatingExpenses);
        $grossProfit = $sales->minus($cogs);

        $receivables = $this->balances->asAt($business, AccountCode::MarketReceivables, $date);
        $payables = $this->balances->asAt($business, AccountCode::CompanyPayables, $date);

        return [
            'date' => $date,
            'position' => $position,

            'stock' => $this->balances->asAt($business, AccountCode::Stock, $date),
            'cash' => $this->balances->asAt($business, AccountCode::Cash, $date),
            'receivables' => $receivables,
            'payables' => $payables,
            'customerAdvances' => $this->balances->asAt($business, AccountCode::CustomerAdvances, $date),
            'companyAdvances' => $this->balances->asAt($business, AccountCode::AdvancesToCompanies, $date),
            'openingEquity' => $this->balances->asAt($business, AccountCode::OpeningBalanceEquity, $date),

            'today' => [
                'purchases' => $movement(AccountCode::Stock),
                'sales' => $sales,
                'cogs' => $cogs,
                'grossProfit' => $grossProfit,
                'expenses' => $expenses,
                'netProfit' => $grossProfit->minus($expenses)
                    ->minus($movement(AccountCode::StockVariance))
                    ->minus($movement(AccountCode::BadDebts)),
                'collections' => $movement(AccountCode::Cash),
                // The figure that deserves equal billing with profit.
                'receivableDelta' => $receivables
                    ->minus($this->balances->asAt($business, AccountCode::MarketReceivables, $previous)),
                'payableDelta' => $payables
                    ->minus($this->balances->asAt($business, AccountCode::CompanyPayables, $previous)),
            ],

            'confidence' => $this->confidence->for($business),
            'alerts' => $this->alerts->for($business),

            'integrity' => [
                'trial' => $this->balances->trialBalance($business, $date),
                'positionConsistent' => $position->isConsistent(),
                'positionDiscrepancy' => $position->discrepancy(),
                'lastClosed' => $business->locked_through_date,
                'openDays' => $this->alerts->unclosedDays($business),
            ],

            'trend' => $this->trend($business),
        ];
    }

    /** Read from closings, not the ledger — that is what the table is for. */
    private function trend(Business $business, int $days = 30): array
    {
        $closings = DailyClosing::forBusiness($business)
            ->finalized()
            ->orderBy('business_date')
            ->take($days)
            ->get();

        return [
            'labels' => $closings->map(fn ($c) => $c->business_date->format('d M'))->all(),
            'series' => [
                'Sales' => $closings->map(fn ($c) => (float) $c->sales->toDecimal())->all(),
                'Gross profit' => $closings->map(fn ($c) => (float) $c->gross_profit->toDecimal())->all(),
                'Market receivables' => $closings->map(fn ($c) => (float) $c->closing_receivable->toDecimal())->all(),
                'Company payables' => $closings->map(fn ($c) => (float) $c->closing_payable->toDecimal())->all(),
                'Cash' => $closings->map(fn ($c) => (float) $c->closing_cash->toDecimal())->all(),
            ],
        ];
    }
}
