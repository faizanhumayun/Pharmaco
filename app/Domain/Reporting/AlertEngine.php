<?php

namespace App\Domain\Reporting;

use App\Domain\Stock\StockConfidence;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\DailyEntry;
use App\Support\Money;

/**
 * Calculated indicators, never hand-entered warnings.
 *
 * The process alerts at the end matter as much as the financial ones: they are
 * what keeps the financial figures meaningful in the first place.
 */
class AlertEngine
{
    public function __construct(private readonly StockConfidence $confidence) {}

    /** @return array<int, array{level: string, title: string, detail: string}> */
    public function for(Business $business): array
    {
        $alerts = [];
        $recent = DailyClosing::forBusiness($business)
            ->finalized()
            ->orderByDesc('business_date')
            ->take(30)
            ->get();

        $week = $recent->take(7);

        if ($week->isNotEmpty()) {
            $creditSales = Money::sum($week->pluck('credit_sales'));
            $collections = Money::sum($week->pluck('collections'));
            $receivableGrowth = Money::sum($week->pluck('receivable_delta'));
            $sales = Money::sum($week->pluck('sales'));

            // A distributor can be profitable every day while going broke,
            // because the profit is sitting in a pharmacy's ledger.
            if ($sales->isPositive() && $this->ratio($receivableGrowth, $sales) > 0.25) {
                $alerts[] = [
                    'level' => 'critical',
                    'title' => 'Market credit rising fast',
                    'detail' => sprintf(
                        'Receivables grew %s over 7 days against sales of %s. More than a quarter of what '
                        . 'you sold has stayed in the market.',
                        $receivableGrowth->format(), $sales->format()
                    ),
                ];
            }

            if ($creditSales->isPositive() && $this->ratio($collections, $creditSales) < 0.70) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => 'Collections lagging sales',
                    'detail' => sprintf(
                        'Collected %s against %s of credit sales this week — %.0f%%.',
                        $collections->format(), $creditSales->format(),
                        $this->ratio($collections, $creditSales) * 100
                    ),
                ];
            }
        }

        if ($recent->isNotEmpty()) {
            $latest = $recent->first();
            $avgExpense = Money::sum($recent->pluck('expenses'))->times(1 / max($recent->count(), 1));

            if ($avgExpense->isPositive()
                && $this->ratio($latest->closing_cash, $avgExpense) < 3) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => 'Cash cover is thin',
                    'detail' => sprintf(
                        'Cash of %s is under three days of average daily expense (%s).',
                        $latest->closing_cash->format(), $avgExpense->format()
                    ),
                ];
            }

            $avgSales = Money::sum($recent->pluck('sales'))->times(1 / max($recent->count(), 1));

            if ($avgSales->isPositive()
                && $this->ratio($latest->closing_receivable, $avgSales) > 45) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => 'Large outstanding market balance',
                    'detail' => sprintf(
                        'Receivables of %s are over 45 days of average sales.',
                        $latest->closing_receivable->format()
                    ),
                ];
            }

            $variances = $recent->take(7)->filter(fn (DailyClosing $c) => $c->hasCashVariance())->count();

            if ($variances >= 3) {
                $alerts[] = [
                    'level' => 'critical',
                    'title' => 'Cash variances recurring',
                    'detail' => "{$variances} of the last 7 closings had a cash difference. "
                        . 'That is a process problem, and often the first sign of a bigger one.',
                ];
            }
        }

        // --- Process alerts: these keep the financial ones meaningful --------
        $stock = $this->confidence->for($business);

        if ($stock['stale']) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Stock value unverified',
                'detail' => $stock['detail'] . ' Stock is the one balance with no independent check.',
            ];
        }

        $openDays = $this->unclosedDays($business);

        if ($openDays > 0) {
            $alerts[] = [
                'level' => $openDays > 3 ? 'critical' : 'warning',
                'title' => $openDays === 1 ? 'A day is still open' : "{$openDays} days are still open",
                'detail' => 'Historical figures get their credibility from somebody agreeing they were right.',
            ];
        }

        return $alerts;
    }

    public function unclosedDays(Business $business): int
    {
        if ($business->opening_date === null) {
            return 0;
        }

        $from = $business->locked_through_date?->copy()->addDay()
            ?? $business->opening_date->copy()->addDay();

        $yesterday = $business->today()->subDay();

        if ($from->greaterThan($yesterday)) {
            return 0;
        }

        return DailyEntry::forBusiness($business)
            ->posted()
            ->whereBetween('business_date', [$from->toDateString(), $yesterday->toDateString()])
            ->count();
    }

    private function ratio(Money $a, Money $b): float
    {
        if ($b->isZero()) {
            return 0;
        }

        return (float) $a->toDecimal() / (float) $b->toDecimal();
    }
}
