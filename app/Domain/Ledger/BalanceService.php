<?php

namespace App\Domain\Ledger;

use App\Enums\AccountCode;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Business;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only class that answers "what is the balance of account X at date Z".
 *
 * Every card, report and export composes these methods. The moment two code
 * paths compute "market receivables" independently they will eventually
 * disagree, and that support conversation is unwinnable.
 */
class BalanceService
{
    /**
     * The balance of one account as at a date, in its natural direction.
     *
     * Rolls up sub-accounts: asking for Company Payables returns the total
     * across every company, because that is what the question means. Without
     * the roll-up the headline payable would read zero the moment Phase 9 moves
     * postings onto per-company children.
     */
    public function asAt(Business $business, AccountCode|string $code, Carbon|string|null $date = null): Money
    {
        return $this->rawFor($business, $this->accountIds($business, $code), null, $date)
            ->times($this->signFor($business, $code));
    }

    /** How much an account and its sub-accounts moved between two dates, inclusive. */
    public function movement(
        Business $business,
        AccountCode|string $code,
        Carbon|string $from,
        Carbon|string $to,
    ): Money {
        return $this->rawFor($business, $this->accountIds($business, $code), $from, $to)
            ->times($this->signFor($business, $code));
    }

    /** The account's own postings, excluding any sub-accounts. */
    public function directBalance(Business $business, AccountCode|string $code, Carbon|string|null $date = null): Money
    {
        $account = $this->account($business, $code);

        return $this->rawFor($business, [$account->id], null, $date)
            ->times($this->signFor($business, $account->code));
    }

    /**
     * Every account's balance as at a date, keyed by code.
     *
     * One query for the whole chart, rather than one per card.
     *
     * @return Collection<string, Money>
     */
    public function all(Business $business, Carbon|string|null $date = null): Collection
    {
        $rows = $this->baseQuery($business, null, $date)
            ->select('accounts.code', 'accounts.type')
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as debits')
            ->selectRaw('COALESCE(SUM(ledger_entries.credit), 0) as credits')
            ->groupBy('accounts.code', 'accounts.type')
            ->get();

        $direct = $rows->mapWithKeys(fn ($row) => [
            $row->code => Money::of($row->debits)
                ->minus($row->credits)
                ->times($this->signFor($business, $row->code, AccountType::from($row->type))),
        ]);

        $accounts = Account::query()->forBusiness($business)->get();

        // Accounts with no activity are zero, not absent — a missing key is how
        // a dashboard ends up rendering a blank instead of a nought.
        $balances = $accounts->mapWithKeys(
            fn (Account $a) => [$a->code => $direct->get($a->code, Money::zero())]
        );

        // Roll each sub-account up into its parent, so this agrees with asAt().
        $byId = $accounts->keyBy('id');

        foreach ($accounts as $account) {
            $parentId = $account->parent_id;

            while ($parentId !== null && $byId->has($parentId)) {
                $parent = $byId->get($parentId);
                $balances[$parent->code] = $balances[$parent->code]->plus($balances[$account->code]);
                $parentId = $parent->parent_id;
            }
        }

        return $balances;
    }

    /** The management position: assets, liabilities, and both derivations of net worth. */
    public function position(Business $business, Carbon|string|null $date = null): PositionSummary
    {
        $date = $this->resolveDate($business, $date);
        $balances = $this->all($business, $date);

        $totals = $this->totalsByType($business, $date);

        return new PositionSummary(
            asAt: $date,
            balances: $balances,
            assets: $totals[AccountType::Asset->value],
            liabilities: $totals[AccountType::Liability->value],
            equity: $totals[AccountType::Equity->value],
            income: $totals[AccountType::Income->value],
            expenses: $totals[AccountType::Expense->value],
        );
    }

    /**
     * The trial balance check: total debits must equal total credits.
     *
     * If this ever fails, something is broken and every other figure in the
     * business is suspect. It costs one query, so it runs nightly and on the
     * dashboard.
     */
    public function trialBalance(Business $business, Carbon|string|null $date = null): array
    {
        $row = $this->baseQuery($business, null, $date)
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as debits')
            ->selectRaw('COALESCE(SUM(ledger_entries.credit), 0) as credits')
            ->first();

        $debits = Money::of($row->debits ?? 0);
        $credits = Money::of($row->credits ?? 0);

        return [
            'debits' => $debits,
            'credits' => $credits,
            'difference' => $debits->minus($credits),
            'balanced' => $debits->equals($credits),
        ];
    }

    /**
     * A control account's balance must equal the sum of its children's.
     *
     * Under this design that is true by construction rather than maintained —
     * this method exists to prove it, not to make it so.
     */
    public function controlReconciles(Business $business, AccountCode|string $code, Carbon|string|null $date = null): bool
    {
        $code = $code instanceof AccountCode ? $code->value : $code;

        $parent = Account::query()->forBusiness($business)->code($code)->with('children')->firstOrFail();

        if ($parent->children->isEmpty()) {
            return true;
        }

        $childTotal = Money::sum(
            $parent->children->map(fn (Account $child) => $this->asAt($business, $child->code, $date))
        );

        // The rolled-up balance equals the sum of the children exactly when the
        // parent carries no direct postings of its own — which is the thing
        // worth proving, since a stray direct posting is the only way the total
        // and the parts can drift apart.
        return $this->asAt($business, $code, $date)->equals($childTotal);
    }

    /** @return array<string, Money> */
    private function totalsByType(Business $business, Carbon|string|null $date): array
    {
        $rows = $this->baseQuery($business, null, $date)
            ->select('accounts.type')
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as debits')
            ->selectRaw('COALESCE(SUM(ledger_entries.credit), 0) as credits')
            ->groupBy('accounts.type')
            ->get()
            ->keyBy('type');

        $totals = [];

        foreach (AccountType::cases() as $type) {
            $row = $rows->get($type->value);

            $totals[$type->value] = $row
                ? Money::of($row->debits)->minus($row->credits)->times($type->presentationSign())
                : Money::zero();
        }

        return $totals;
    }

    /** Raw (debit − credit) over a set of accounts, before the presentation sign. */
    private function rawFor(
        Business $business,
        array $accountIds,
        Carbon|string|null $from,
        Carbon|string|null $to,
    ): Money {
        if ($accountIds === []) {
            return Money::zero();
        }

        $row = $this->baseQuery($business, $accountIds, $to, $from)
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as debits')
            ->selectRaw('COALESCE(SUM(ledger_entries.credit), 0) as credits')
            ->first();

        return Money::of($row->debits ?? 0)->minus($row->credits ?? 0);
    }

    private function account(Business $business, AccountCode|string $code): Account
    {
        return Account::query()->forBusiness($business)->code($code)->firstOrFail();
    }

    /**
     * The multiplier turning raw (debit − credit) into what a reader expects.
     *
     * Note that totals by type deliberately do NOT use this: in the equity
     * total, drawings must still subtract.
     */
    public function signFor(Business $business, AccountCode|string $code, ?AccountType $type = null): int
    {
        $enum = $code instanceof AccountCode ? $code : AccountCode::tryFrom((string) $code);
        $type ??= $this->typeOf($business, $code);

        return $type->presentationSign() * ($enum?->isContra() ? -1 : 1);
    }

    private function typeOf(Business $business, AccountCode|string $code): AccountType
    {
        if ($code instanceof AccountCode) {
            return $code->type();
        }

        return AccountCode::tryFrom($code)?->type() ?? $this->account($business, $code)->type;
    }

    /**
     * The account named by the code, plus every account beneath it.
     *
     * @return array<int, int>
     */
    private function accountIds(Business $business, AccountCode|string $code): array
    {
        $accounts = Account::query()->forBusiness($business)->get(['id', 'code', 'parent_id']);
        $codeValue = $code instanceof AccountCode ? $code->value : $code;

        $root = $accounts->firstWhere('code', $codeValue);

        if ($root === null) {
            return [];
        }

        $ids = [$root->id];
        $frontier = [$root->id];

        while ($frontier !== []) {
            $children = $accounts->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    /**
     * The one hot query shape in this application.
     *
     * Deliberately a query builder rather than Eloquent: it aggregates, and it
     * takes business_id as a required argument rather than relying on a global
     * scope, because a global scope does not apply to raw aggregates and this
     * is exactly where a tenancy leak would hide.
     */
    private function baseQuery(
        Business $business,
        ?array $accountIds,
        Carbon|string|null $to,
        Carbon|string|null $from = null,
    ) {
        $query = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->join('transactions', 'transactions.id', '=', 'ledger_entries.transaction_id')
            ->where('ledger_entries.business_id', $business->id)
            // Drafts have no ledger effect. A single report that includes them
            // produces a number nobody can reconcile.
            ->where('transactions.status', '!=', 'draft');

        if ($accountIds !== null) {
            $query->whereIn('accounts.id', $accountIds);
        }

        if ($from !== null) {
            $query->where('ledger_entries.business_date', '>=', $this->toDateString($from));
        }

        if ($to !== null) {
            $query->where('ledger_entries.business_date', '<=', $this->toDateString($to));
        }

        return $query;
    }

    private function toDateString(Carbon|string $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : $date;
    }

    private function resolveDate(Business $business, Carbon|string|null $date): Carbon
    {
        return match (true) {
            $date === null => $business->today(),
            $date instanceof Carbon => $date->copy(),
            default => Carbon::parse($date),
        };
    }
}
