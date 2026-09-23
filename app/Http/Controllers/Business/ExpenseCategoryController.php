<?php

namespace App\Http\Controllers\Business;

use App\Domain\Expenses\Actions\CreateExpenseCategory;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use App\Http\Requests\Business\StoreExpenseRequest;
use App\Exceptions\LedgerException;
use App\Domain\Expenses\Actions\AddExpenseToDay;
use App\Domain\Ledger\BalanceService;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ExpenseCategory;
use App\Models\DailyEntry;
use App\Models\ExpenseLine;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The spending side of CompanyController, in the same shape.
 *
 * Expenses are cumulative rather than owed: a head's balance is everything
 * spent under it since the books opened, so the statement runs one way only.
 */
class ExpenseCategoryController extends Controller
{
    public function index(Request $request, Business $business): View
    {
        $this->authorize('configure', $business);

        $categories = ExpenseCategory::forBusiness($business)
            ->with('account')->orderBy('sort_order')->orderBy('name')->get();

        // Filter by head — only one of this business's own, whatever the URL says.
        $head = $categories->firstWhere('id', $request->integer('head'));

        // And by when: a named range, or a custom one. Business-local dates.
        [$range, $from, $to] = $this->range($business, $request);
        $inRange = fn ($q) => $q->where('business_id', $business->id)
            ->when($from, fn ($q) => $q->where('business_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('business_date', '<=', $to->toDateString()));

        /*
         * Every expense as it was entered on a day, newest first. Expense lines
         * carry no business of their own; they belong to one through their day,
         * so they are only ever reached through this business's days.
         */
        $expenses = ExpenseLine::query()
            ->whereHas('dailyEntry', $inRange)
            ->when($head, fn ($q) => $q->where('expense_category_id', $head->id))
            ->with(['category', 'dailyEntry.creator'])
            ->orderByDesc(
                DailyEntry::select('business_date')->whereColumn('daily_entries.id', 'expense_lines.daily_entry_id')
            )
            ->orderByDesc('id');

        $shownTotal = Money::of((clone $expenses)->sum('amount'));


        /*
         * Per-head totals. Once heads have ledgers of their own, the ledger is
         * the authority. Until then every head shares the one expenses account
         * and its ledger total is zero, so the totals come from what was
         * entered under each head on posted days — the same figures the books
         * hold, just not yet split by account.
         */
        /*
         * Per-head totals for the chosen period, from what was entered under
         * each head on posted days — the same amounts the books hold. Heads
         * with ledgers of their own agree with those ledgers; heads still
         * sharing the one expenses account have no ledger figure of their own.
         */
        $headTotals = $categories->mapWithKeys(fn (ExpenseCategory $c) => [
            $c->id => Money::of(ExpenseLine::query()
                ->where('expense_category_id', $c->id)
                ->whereHas('dailyEntry', fn ($q) => $inRange($q)
                    ->where('status', \App\Enums\DocumentStatus::Posted->value))
                ->sum('amount')),
        ]);

        return view('business.expenses.index', [
            'business' => $business,
            'categories' => $categories,
            'balances' => $headTotals,
            'expenses' => $expenses->paginate(50)->withQueryString(),
            'shownTotal' => $shownTotal,
            'head' => $head,
            'range' => $range,
            'ranges' => self::RANGES,
            'from' => $from,
            'to' => $to,
            // For the add-expense drawer: the days it may go on.
            'earliestDay' => collect([
                $business->locked_through_date?->copy()->addDay(),
                $business->opening_date?->copy()->addDay(),
            ])->filter()->max(),
        ]);
    }

    /** Named periods an owner actually asks for. */
    private const RANGES = [
        'all' => 'All time',
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'custom' => 'Custom dates',
    ];

    /** @return array{0: string, 1: ?Carbon, 2: ?Carbon} */
    private function range(Business $business, Request $request): array
    {
        $range = array_key_exists($request->string('range')->toString(), self::RANGES)
            ? $request->string('range')->toString()
            : 'all';

        $today = Carbon::parse($business->today()->toDateString());

        [$from, $to] = match ($range) {
            'today' => [$today->copy(), $today->copy()],
            '7d' => [$today->copy()->subDays(6), $today->copy()],
            '30d' => [$today->copy()->subDays(29), $today->copy()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'custom' => [
                $request->date('from') ? Carbon::parse($request->date('from')->toDateString()) : null,
                $request->date('to') ? Carbon::parse($request->date('to')->toDateString()) : null,
            ],
            default => [null, null],
        };

        return [$range, $from, $to];
    }

    /**
     * One expense, added to the day it belongs to — as if typed on that
     * day's entry. See AddExpenseToDay.
     */
    public function storeExpense(StoreExpenseRequest $request, Business $business, AddExpenseToDay $action): RedirectResponse
    {
        try {
            $entry = $action->handle(
                $business,
                Carbon::parse($request->string('business_date')->toString()),
                $request->string('category')->toString(),
                $request->input('description'),
                Money::of($request->string('amount')->toString()),
                $request->user(),
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['business_date' => $e->getMessage()]);
        }

        return back()->with('status',
            'Rs. ' . Money::of($request->string('amount')->toString())->format() . ' added to '
            . $entry->business_date->format('D d M Y') . "'s entry"
            . ($entry->isEditable() ? ' (still a draft).' : ' and posted.'));
    }

    public function store(Request $request, Business $business, CreateExpenseCategory $action): RedirectResponse
    {
        $this->authorize('configure', $business);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $category = $action->handle($business, $validated, $request->user());

        return back()->with('status', "{$category->name} added with its own ledger ({$category->account->code}).");
    }

    public function show(Business $business, ExpenseCategory $expense, BalanceService $balances): View
    {
        $this->authorize('configure', $business);

        abort_if($expense->business_id !== $business->id, 403);

        $hasOwnLedger = $expense->account && $expense->account->parent_id !== null;

        $entries = $hasOwnLedger
            ? LedgerEntry::forBusiness($business)
                ->where('account_id', $expense->account_id)
                ->with(['transaction.creator'])
                ->orderBy('business_date')->orderBy('id')->get()
            : collect();

        $running = Money::zero();

        $rows = $entries->map(function (LedgerEntry $entry) use (&$running) {
            // Expenses are debit-normal: spending raises the total.
            $running = $running->plus($entry->debit)->minus($entry->credit);

            return ['entry' => $entry, 'running' => $running];
        });

        return view('business.expenses.show', [
            'business' => $business,
            'category' => $expense,
            'rows' => $rows,
            'hasOwnLedger' => $hasOwnLedger,
            'balance' => $hasOwnLedger ? $balances->asAt($business, $expense->account->code) : Money::zero(),
        ]);
    }
}
