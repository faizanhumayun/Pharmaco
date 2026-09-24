<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Domain\Market\Outstanding;
use App\Domain\Pharmacies\Actions\CreatePharmacy;
use App\Enums\AccountCode;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\LedgerEntry;
use App\Models\Pharmacy;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The selling side of CompanyController, deliberately identical in shape.
 *
 * The only real difference is which way the balance runs: a pharmacy owes the
 * distributor, so its ledger is debit-normal.
 */
class PharmacyController extends Controller
{
    public function index(Request $request, Business $business, BalanceService $balances): View
    {
        $this->authorize('configure', $business);

        // Name, area or phone — whatever the reader has to hand.
        $search = trim((string) $request->string('q'));

        $pharmacies = Pharmacy::forBusiness($business)
            ->with('account')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('area', 'like', '%' . $search . '%')
                ->orWhere('phone', 'like', '%' . $search . '%')
                ->orWhere('code', 'like', '%' . $search . '%')))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        /*
         * Every customer's balance in one query. Asking the balance service per
         * customer is a query each — fine for a handful, and a page that never
         * finishes once a real customer list is imported.
         */
        $owed = LedgerEntry::query()
            ->where('business_id', $business->id)
            ->whereIn('account_id', $pharmacies->pluck('account_id')->filter())
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->groupBy('account_id')
            ->pluck('balance', 'account_id');

        $owedBy = $pharmacies->mapWithKeys(fn (Pharmacy $p) => [
            $p->id => Money::of($owed[$p->account_id] ?? 0),
        ]);

        $total = $balances->asAt($business, AccountCode::MarketReceivables);

        // The figure under every customer's name, not only this page's.
        $sum = Money::of(LedgerEntry::query()
            ->where('business_id', $business->id)
            ->whereIn('account_id', Pharmacy::forBusiness($business)->pluck('account_id')->filter())
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance') ?? 0);

        return view('business.pharmacies.index', [
            'business' => $business,
            'pharmacies' => $pharmacies,
            'balances' => $owedBy,
            'total' => $total,
            'search' => $search,
            // Stated as a fact, not maintained as a figure.
            'unallocated' => $total->minus($sum),
            'reconciles' => $balances->controlReconciles($business, AccountCode::MarketReceivables),
        ]);
    }

    public function store(Request $request, Business $business, CreatePharmacy $action): RedirectResponse|JsonResponse
    {
        $this->authorize('configure', $business);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'contact' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'area' => ['nullable', 'string', 'max:120'],
        ]);

        $pharmacy = $action->handle($business, $validated, $request->user());

        // The counter adds a customer in the middle of a bill: it needs the new
        // customer back, not a page, or the bill on screen would be lost.
        if ($request->expectsJson()) {
            return response()->json([
                'id' => $pharmacy->id,
                'name' => $pharmacy->name,
                'area' => $pharmacy->area,
                'phone' => $pharmacy->phone,
                'owes' => 0,
            ], 201);
        }

        return back()->with('status', "{$pharmacy->name} added with its own ledger ({$pharmacy->account->code}).");
    }

    public function show(
        Request $request,
        Business $business,
        Pharmacy $pharmacy,
        BalanceService $balances,
        Outstanding $outstanding,
    ): View {
        $this->authorize('configure', $business);

        abort_if($pharmacy->business_id !== $business->id, 403);

        $today = $business->today();

        /*
         * A statement is read a period at a time — "what happened last month" —
         * so the default is the last ninety days rather than everything since
         * the business opened. Whatever came before is one line: brought
         * forward. That line is what makes a partial statement still add up.
         */
        $from = $request->date('from') ?? $today->copy()->subDays(90);
        $to = $request->date('to') ?? $today;

        if ($request->query('period') === 'all' && $business->opening_date !== null) {
            $from = $business->opening_date->copy();
        }

        $broughtForward = $pharmacy->account
            ? $balances->asAt($business, $pharmacy->account->code, $from->copy()->subDay())
            : Money::zero();

        /*
         * A day is amended by reversing every posting and writing them again,
         * so a busy day leaves the same invoice on this account five times
         * over, each cancelled by a reversal. True, and unreadable: a customer
         * statement showing one sale six times is worse than no statement.
         *
         * So by default this shows the postings that still stand. Nothing is
         * hidden for good — "show corrections" puts the whole trail back, and
         * the Activity log has it regardless.
         */
        $showReversals = $request->boolean('corrections');

        $entries = $pharmacy->account
            ? LedgerEntry::forBusiness($business)
                ->where('account_id', $pharmacy->account_id)
                ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                ->unless($showReversals, fn ($q) => $q->whereHas(
                    'transaction',
                    fn ($t) => $t->whereNull('reversal_of_id')->whereNull('reversed_by_id')
                ))
                ->with(['transaction.creator'])
                ->orderBy('business_date')->orderBy('id')
                ->paginate(100)
                ->withQueryString()
            : null;

        /*
         * The running balance has to continue from what came before this page,
         * not restart at zero — otherwise page two of a statement reads as a
         * different customer. One aggregate for everything earlier in the
         * period, rather than fetching those rows again.
         */
        $running = $broughtForward->plus($this->earlierInPeriod($business, $pharmacy, $from, $entries, $showReversals));

        $rows = collect($entries?->items() ?? [])->map(function (LedgerEntry $entry) use (&$running) {
            // Receivables are debit-normal: a debit raises what is owed to us.
            $running = $running->plus($entry->debit)->minus($entry->credit);

            return ['entry' => $entry, 'running' => $running];
        });

        // What makes up the balance right now, and how long it has waited.
        $collected = $outstanding->collectedByBill($business);
        $open = $outstanding->bills($business, $pharmacy)
            ->each(fn ($bill) => $bill->setAttribute('collected', $collected[$bill->id] ?? Money::zero()))
            ->filter(fn ($bill) => $outstanding->onBill($bill)->isPositive())
            ->map(fn ($bill) => [
                'bill' => $bill,
                'owed' => $outstanding->onBill($bill),
                'age' => $outstanding->ageOf($bill, $today),
            ])
            ->values();

        $balance = $pharmacy->account
            ? $balances->asAt($business, $pharmacy->account->code)
            : Money::zero();

        return view('business.pharmacies.show', [
            'business' => $business,
            'pharmacy' => $pharmacy,
            'rows' => $rows,
            'entries' => $entries,
            'broughtForward' => $broughtForward,
            'balance' => $balance,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'period' => $request->query('period'),
            'showReversals' => $showReversals,
            'open' => $open,
            // Past the credit days agreed with them, which is the figure worth
            // chasing rather than the whole balance.
            'overdue' => Money::sum($open->filter(
                fn ($row) => $row['age'] > (int) ($pharmacy->credit_days ?? 0)
            )->pluck('owed')),
            'ageing' => $open->groupBy(fn ($row) => $outstanding->bandFor($row['age']))
                ->map(fn ($rows) => Money::sum($rows->pluck('owed'))),
            'lastPaid' => $pharmacy->account
                ? LedgerEntry::forBusiness($business)
                    ->where('account_id', $pharmacy->account_id)
                    ->where('credit', '>', 0)
                    ->orderByDesc('business_date')->orderByDesc('id')
                    ->first()
                : null,
            'dayClosed' => $business->isDayClosed($today),
            // The customer payload the collect dialog needs.
            'collectable' => [
                'pharmacy_id' => $pharmacy->id,
                'name' => $pharmacy->name,
                'owed' => (float) Money::sum($open->pluck('owed'))->toDecimal(),
                'bills' => $open->map(fn ($row) => [
                    'id' => $row['bill']->id,
                    'ref' => $row['bill']->reference(),
                    'owed' => (float) $row['owed']->toDecimal(),
                    'age' => $row['age'],
                ])->all(),
            ],
        ]);
    }

    /**
     * The movement inside the period that comes before the page being shown.
     *
     * @param  \Illuminate\Pagination\LengthAwarePaginator|null  $entries
     */
    private function earlierInPeriod(Business $business, Pharmacy $pharmacy, \Illuminate\Support\Carbon $from, $entries, bool $showReversals = false): Money
    {
        $first = collect($entries?->items() ?? [])->first();

        if ($first === null || $entries->currentPage() === 1) {
            return Money::zero();
        }

        $totals = LedgerEntry::forBusiness($business)
            ->where('account_id', $pharmacy->account_id)
            ->where('business_date', '>=', $from->toDateString())
            ->where('id', '<', $first->id)
            ->unless($showReversals, fn ($q) => $q->whereHas(
                'transaction',
                fn ($t) => $t->whereNull('reversal_of_id')->whereNull('reversed_by_id')
            ))
            ->selectRaw('COALESCE(SUM(debit), 0) as debits, COALESCE(SUM(credit), 0) as credits')
            ->first();

        return Money::of($totals->debits)->minus(Money::of($totals->credits));
    }
}
