<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Business;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How the business has moved over time.
 *
 * Built from the ledger rather than from daily_closings, so a period reads
 * correctly whether or not its days have been closed yet — an owner looking at
 * progress should not have to close a day to see it.
 *
 * Balances are carried forward: each period's closing figure is the previous
 * one plus that period's movement, starting from the real balance the day
 * before the range.
 */
class HistoryQuery
{
    public function __construct(private readonly BalanceService $balances) {}

    /** Accounts whose closing balance is worth plotting. */
    private const BALANCE_CODES = [
        AccountCode::Cash,
        AccountCode::MarketReceivables,
        AccountCode::CompanyPayables,
        AccountCode::Stock,
    ];

    public function build(Business $business, Carbon $from, Carbon $to, string $granularity = 'day'): array
    {
        $periods = $this->periods($from, $to, $granularity);

        $movements = $this->movementsByDate($business, $from, $to);
        $byType = $this->typeTotalsByDate($business, $from, $to);
        $opening = collect($this->balances->all($business, $from->copy()->subDay()))->all();

        /*
         * The opening balances arrive rolled up — a control account already
         * includes its children. The per-date movements do not, so without
         * this a posting onto a company's own account would never reach
         * Company Payables, and the headline payable would read zero from the
         * moment the first company was created. Same reason BalanceService
         * rolls up: the control IS the sum of its children.
         */
        $ancestors = $this->ancestorCodes($business);
        $roots = $this->rootCodesByType($business);

        /*
         * A correction to the opening balance restates where the business
         * started. It can only be posted in the open period, but it belongs to
         * the opening date — so here it joins the balances the range starts
         * from, and comes off the day it happened to be posted. Otherwise the
         * opening row shows the figure that was corrected, and a later day
         * shows a jump nobody traded. Includes corrections posted after the
         * range: the start was still different from what was first entered.
         */
        $restated = false;

        foreach ($this->openingCorrections($business, $from) as $date => $byCode) {
            foreach ($byCode as $code => $amount) {
                foreach ([$code, ...($ancestors[$code] ?? [])] as $target) {
                    $opening[$target] = ($opening[$target] ?? Money::zero())->plus($amount);
                }

                if (isset($movements[$date][$code])) {
                    $movements[$date][$code] = $movements[$date][$code]->minus($amount);
                }

                $restated = true;
            }
        }

        $running = $opening;

        $rows = [];

        foreach ($periods as $period) {
            $flow = [];

            // Everything that happened inside this period, per account.
            foreach ($period['dates'] as $date) {
                foreach ($movements[$date] ?? [] as $code => $amount) {
                    foreach ([$code, ...($ancestors[$code] ?? [])] as $target) {
                        $flow[$target] = ($flow[$target] ?? Money::zero())->plus($amount);
                        $running[$target] = ($running[$target] ?? Money::zero())->plus($amount);
                    }
                }
            }

            $types = [];

            foreach ($period['dates'] as $date) {
                foreach ($byType[$date] ?? [] as $type => $amount) {
                    $types[$type] = ($types[$type] ?? Money::zero())->plus($amount);
                }
            }

            $get = fn (AccountCode $c) => $flow[$c->value] ?? Money::zero();
            $type = fn (TransactionType $t) => $types[$t->value] ?? Money::zero();

            $sales = $get(AccountCode::Sales)->minus($get(AccountCode::SalesReturns));
            $cogs = $get(AccountCode::CostOfGoodsSold);
            $expenses = $get(AccountCode::OperatingExpenses);
            $grossProfit = $sales->minus($cogs);

            $netProfit = $grossProfit
                ->minus($expenses)
                ->minus($get(AccountCode::StockVariance))
                ->minus($get(AccountCode::BadDebts));

            $assets = $this->totalFor($running, AccountType::Asset, $roots);
            $liabilities = $this->totalFor($running, AccountType::Liability, $roots);

            $rows[] = [
                'key' => $period['key'],
                'label' => $period['label'],
                'start' => $period['start'],
                'end' => $period['end'],

                'sales' => $sales,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'expenses' => $expenses,
                'net_profit' => $netProfit,
                'margin' => $sales->isZero()
                    ? null
                    : round((float) $grossProfit->toDecimal() / (float) $sales->toDecimal() * 100, 2),

                'purchases' => $type(TransactionType::PurchaseCredit)->plus($type(TransactionType::PurchaseCash)),
                'credit_sales' => $type(TransactionType::SaleCredit),
                'cash_sales' => $type(TransactionType::SaleCash),
                'collections' => $type(TransactionType::Collection),
                'company_payments' => $type(TransactionType::CompanyPayment),

                'cash' => $running[AccountCode::Cash->value] ?? Money::zero(),
                'receivables' => $running[AccountCode::MarketReceivables->value] ?? Money::zero(),
                'payables' => $running[AccountCode::CompanyPayables->value] ?? Money::zero(),
                'stock' => $running[AccountCode::Stock->value] ?? Money::zero(),
                'assets' => $assets,
                'liabilities' => $liabilities,
                'net_position' => $assets->minus($liabilities),

                // The figure that deserves equal billing with profit.
                'receivable_delta' => $get(AccountCode::MarketReceivables),
                'payable_delta' => $get(AccountCode::CompanyPayables),
            ];
        }

        return [
            'rows' => $rows,
            'opening' => [...$this->openingRow($opening, $from, $roots), 'restated' => $restated],
            'totals' => $this->totals($rows),
            'granularity' => $granularity,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * The balances the range starts from.
     *
     * Every closing figure in the table is this plus the movement above it, so
     * without it the first period's balances look like they appeared from
     * nowhere — particularly on the opening day, where the whole position is
     * carried in and none of it is activity.
     *
     * @param  iterable<string, Money>  $opening  balances the day before the range
     * @param  array<string, array<int, string>>  $roots
     */
    private function openingRow(iterable $opening, Carbon $from, array $roots): array
    {
        $balances = collect($opening)->all();

        $at = fn (AccountCode $code) => $balances[$code->value] ?? Money::zero();

        $assets = $this->totalFor($balances, AccountType::Asset, $roots);
        $liabilities = $this->totalFor($balances, AccountType::Liability, $roots);

        return [
            'date' => $from->copy()->subDay(),
            'cash' => $at(AccountCode::Cash),
            'receivables' => $at(AccountCode::MarketReceivables),
            'payables' => $at(AccountCode::CompanyPayables),
            'stock' => $at(AccountCode::Stock),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'net_position' => $assets->minus($liabilities),
        ];
    }

    /** The same shape for the preceding range, so progress can be stated as a change. */
    public function comparison(Business $business, Carbon $from, Carbon $to, string $granularity): array
    {
        $length = $from->diffInDays($to) + 1;

        $previous = $this->build(
            $business,
            $from->copy()->subDays($length),
            $from->copy()->subDay(),
            $granularity,
        );

        return $previous['totals'];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function totals(array $rows): array
    {
        $sum = fn (string $key) => Money::sum(array_column($rows, $key));

        $sales = $sum('sales');
        $grossProfit = $sum('gross_profit');

        return [
            'sales' => $sales,
            'cogs' => $sum('cogs'),
            'gross_profit' => $grossProfit,
            'expenses' => $sum('expenses'),
            'net_profit' => $sum('net_profit'),
            'purchases' => $sum('purchases'),
            'collections' => $sum('collections'),
            'company_payments' => $sum('company_payments'),
            'credit_sales' => $sum('credit_sales'),
            'receivable_delta' => $sum('receivable_delta'),
            'payable_delta' => $sum('payable_delta'),
            'margin' => $sales->isZero()
                ? null
                : round((float) $grossProfit->toDecimal() / (float) $sales->toDecimal() * 100, 2),
            'closing' => $rows === [] ? null : end($rows),
        ];
    }

    /**
     * @param  ?\Closure  $only  narrows the transactions counted (see openingCorrections)
     * @return array<string, array<string, Money>> keyed by date then account code
     */
    private function movementsByDate(Business $business, Carbon $from, ?Carbon $to, ?\Closure $only = null): array
    {
        $rows = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->join('transactions', 'transactions.id', '=', 'ledger_entries.transaction_id')
            ->where('ledger_entries.business_id', $business->id)
            ->where('transactions.status', '!=', 'draft')
            ->where('ledger_entries.business_date', '>=', $from->toDateString())
            ->when($to, fn ($q) => $q->where('ledger_entries.business_date', '<=', $to->toDateString()))
            ->when($only, $only)
            ->groupBy('ledger_entries.business_date', 'accounts.code', 'accounts.type')
            ->select('ledger_entries.business_date', 'accounts.code', 'accounts.type')
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as debits')
            ->selectRaw('COALESCE(SUM(ledger_entries.credit), 0) as credits')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->business_date)->toDateString();

            $out[$date][$row->code] = Money::of($row->debits)
                ->minus($row->credits)
                ->times($this->balances->signFor($business, $row->code, AccountType::from($row->type)));
        }

        return $out;
    }

    /**
     * Movements from opening balance corrections that belong before the range
     * but were posted on or after its first day, keyed by the date posted.
     *
     * @return array<string, array<string, Money>>
     */
    private function openingCorrections(Business $business, Carbon $from): array
    {
        $opening = $business->openingBalance;

        if ($opening === null) {
            return [];
        }

        $ids = $opening->corrections()
            ->whereNotNull('original_business_date')
            ->where('original_business_date', '<', $from->toDateString())
            ->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        return $this->movementsByDate($business, $from, null,
            fn ($q) => $q->whereIn('transactions.id', $ids));
    }

    /** @return array<string, array<string, Money>> keyed by date then transaction type */
    private function typeTotalsByDate(Business $business, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('transactions')
            ->where('business_id', $business->id)
            ->where('status', '!=', 'draft')
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('business_date', 'type')
            ->select('business_date', 'type')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[Carbon::parse($row->business_date)->toDateString()][$row->type] = Money::of($row->total);
        }

        // A reversal cancels its original, so it comes off the original's type
        // on the reversal's date. Without this an amended day counted every
        // earlier version of itself (DailyClosingCalculator does the same).
        $reversals = DB::table('transactions')
            ->join('transactions as original', 'original.id', '=', 'transactions.reversal_of_id')
            ->where('transactions.business_id', $business->id)
            ->where('transactions.status', '!=', 'draft')
            ->where('transactions.type', TransactionType::Reversal->value)
            ->whereBetween('transactions.business_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('transactions.business_date', 'original.type')
            ->select('transactions.business_date', 'original.type')
            ->selectRaw('COALESCE(SUM(transactions.amount), 0) as total')
            ->get();

        foreach ($reversals as $row) {
            $date = Carbon::parse($row->business_date)->toDateString();
            $out[$date][$row->type] = ($out[$date][$row->type] ?? Money::zero())->minus(Money::of($row->total));
        }

        return $out;
    }

    /**
     * Totals one side of the position.
     *
     * Sums the top-level accounts only. Every one already contains its
     * children, so adding the children too would count them twice — and
     * classifying by AccountCode::tryFrom() would instead drop them entirely,
     * because a generated code like 2000-001 is not a case of that enum. Either
     * way the answer would be wrong; the account tree is the authority.
     *
     * @param  array<string, Money>  $balances
     * @param  array<string, array<int, string>>  $roots  top-level codes per type
     */
    private function totalFor(array $balances, AccountType $type, array $roots): Money
    {
        $total = Money::zero();

        foreach ($roots[$type->value] ?? [] as $code) {
            $total = $total->plus($balances[$code] ?? Money::zero());
        }

        return $total;
    }

    /**
     * Every account's ancestors, by code.
     *
     * @return array<string, array<int, string>>
     */
    private function ancestorCodes(Business $business): array
    {
        $accounts = Account::query()->forBusiness($business)->get();
        $byId = $accounts->keyBy('id');
        $out = [];

        foreach ($accounts as $account) {
            $chain = [];
            $parentId = $account->parent_id;

            while ($parentId !== null && $byId->has($parentId)) {
                $parent = $byId->get($parentId);
                $chain[] = $parent->code;
                $parentId = $parent->parent_id;
            }

            if ($chain !== []) {
                $out[$account->code] = $chain;
            }
        }

        return $out;
    }

    /**
     * Top-level account codes, grouped by type.
     *
     * @return array<string, array<int, string>>
     */
    private function rootCodesByType(Business $business): array
    {
        return Account::query()
            ->forBusiness($business)
            ->whereNull('parent_id')
            ->get()
            ->groupBy(fn (Account $a) => $a->type->value)
            ->map(fn ($group) => $group->pluck('code')->all())
            ->all();
    }

    /** @return array<int, array{key: string, label: string, start: Carbon, end: Carbon, dates: array<int, string>}> */
    private function periods(Carbon $from, Carbon $to, string $granularity): array
    {
        $buckets = [];

        // Walked as calendar dates: $from and $to can arrive in different
        // timezones (the opening date is stored plain, "today" is the
        // business's), and comparing them as instants dropped the last day.
        $last = $to->toDateString();

        for ($date = Carbon::parse($from->toDateString()); $date->toDateString() <= $last; $date->addDay()) {
            [$key, $label, $start, $end] = match ($granularity) {
                'week' => [
                    $date->copy()->startOfWeek()->toDateString(),
                    $date->copy()->startOfWeek()->format('d M'),
                    $date->copy()->startOfWeek(),
                    $date->copy()->endOfWeek(),
                ],
                'month' => [
                    $date->copy()->startOfMonth()->toDateString(),
                    $date->copy()->format('M Y'),
                    $date->copy()->startOfMonth(),
                    $date->copy()->endOfMonth(),
                ],
                default => [
                    $date->toDateString(),
                    $date->format('d M'),
                    $date->copy(),
                    $date->copy(),
                ],
            };

            $buckets[$key] ??= ['key' => $key, 'label' => $label, 'start' => $start, 'end' => $end, 'dates' => []];
            $buckets[$key]['dates'][] = $date->toDateString();
        }

        return array_values($buckets);
    }
}
