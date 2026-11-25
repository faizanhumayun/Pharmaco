<?php

namespace App\Http\Controllers\Business;

use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Companies\CompanyPurchases;
use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
use App\Domain\Stock\StockLedger;
use App\Models\CompanyProduct;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request, Business $business, BalanceService $balances, CompanyPurchases $purchases): View
    {
        $this->authorize('configure', $business);

        // Name or code, whichever the reader has to hand.
        $search = trim((string) $request->string('q'));
        $status = in_array($request->string('status')->toString(), ['active', 'inactive'], true)
            ? $request->string('status')->toString()
            : 'all';

        $companies = Company::forBusiness($business)
            ->with('account')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('code', 'like', '%' . $search . '%')))
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->get();

        // One query for the page rather than one per company.
        // The days the purchases are counted over; none means all of them.
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = $dates['from'] ?? null;
        $to = $dates['to'] ?? null;

        $totals = $purchases->totals($business, $from, $to);

        // One query for every company's balance, not one query each.
        $ledgerTotals = LedgerEntry::query()
            ->where('business_id', $business->id)
            ->whereIn('account_id', $companies->pluck('account_id')->filter())
            ->selectRaw('account_id, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as balance')
            ->groupBy('account_id')
            ->pluck('balance', 'account_id');

        $owed = $companies->mapWithKeys(fn (Company $c) => [
            $c->id => Money::of($ledgerTotals[$c->account_id] ?? 0),
        ]);

        $total = $balances->asAt($business, AccountCode::CompanyPayables);
        $sum = Money::sum($owed);


        // How many each of the two lists holds, before one is chosen.
        $allCount = $companies->count();
        $pendingCount = $companies->filter(fn (Company $c) => ! $owed[$c->id]->isZero())->count();

        // Only those still owed something, when that is what is being looked for.
        if ($request->boolean('owing')) {
            $companies = $companies->filter(fn (Company $c) => ! $owed[$c->id]->isZero())->values();
        }

        $sort = in_array($request->string('sort')->toString(), ['owed', 'purchased'], true)
            ? $request->string('sort')->toString()
            : 'name';

        $companies = match ($sort) {
            'owed' => $companies->sortByDesc(fn (Company $c) => (float) $owed[$c->id]->toDecimal())->values(),
            'purchased' => $companies->sortByDesc(fn (Company $c) => (float) $totals->get($c->id, CompanyPurchases::none())['billed']->toDecimal())->values(),
            default => $companies,
        };

        $shownOwed = Money::sum($companies->map(fn (Company $c) => $owed[$c->id]));

        // What the companies listed were billed and paid over those days.
        $listedTotals = $companies->map(fn (Company $c) => $totals->get($c->id, CompanyPurchases::none()));
        $listedBilled = Money::sum($listedTotals->pluck('billed'));
        $listedPaid = Money::sum($listedTotals->pluck('paid'));
        $listedInvoices = (int) $listedTotals->sum('invoices');

        // A page at a time, once the list outgrows a screen.
        $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        $companies = new \Illuminate\Pagination\LengthAwarePaginator(
            $companies->forPage($page, 50)->values(),
            $companies->count(),
            50,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('business.companies.index', [
            'business' => $business,
            'companies' => $companies,
            'search' => $search,
            'status' => $status,
            'owing' => $request->boolean('owing'),
            'sort' => $sort,
            'shownOwed' => $shownOwed,
            'from' => $from,
            'to' => $to,
            'listedBilled' => $listedBilled,
            'listedPaid' => $listedPaid,
            'listedInvoices' => $listedInvoices,
            'allCount' => $allCount,
            'pendingCount' => $pendingCount,
            'balances' => $companies->mapWithKeys(fn (Company $c) => [
                $c->id => $owed[$c->id],
            ]),
            'purchases' => $companies->mapWithKeys(fn (Company $c) => [
                $c->id => $totals->get($c->id, CompanyPurchases::none()),
            ]),
            'billedTotal' => $listedBilled,
            'paidTotal' => $listedPaid,
            'total' => $total,
            // The reconciliation the specification asked for, shown as a fact
            // rather than as something the system maintains.
            'unallocated' => $total->minus($sum),
            'reconciles' => $balances->controlReconciles($business, AccountCode::CompanyPayables),
        ]);
    }

    public function store(Request $request, Business $business, CreateCompany $action): RedirectResponse
    {
        $this->authorize('configure', $business);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'contact' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $company = $action->handle($business, $validated, $request->user());

        return back()
            // So a company added from the import screen comes back already
            // chosen, rather than leaving you to find it in the list again.
            ->with('created_company_id', $company->id)
            ->with('status', "{$company->name} added with its own ledger ({$company->account->code}).");
    }

    /**
     * What is held of this company's goods, and what it is worth.
     *
     * The company page grew up as the money side — what was bought and what is
     * owed — and said nothing about the goods themselves, which is half of what
     * a supplier relationship is. Stock is read once for the business and
     * narrowed here rather than asked product by product.
     *
     * @return array<string, mixed>
     */
    private function stockOf(Business $business, Company $company, StockLedger $stock): array
    {
        $products = CompanyProduct::forBusiness($business)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('brand_name')
            ->get();

        $onHand = $stock->onHandByProduct($business);

        $rows = $products->map(function (CompanyProduct $product) use ($onHand) {
            $packs = (int) ($onHand[$product->id] ?? 0);
            $cost = $product->purchase_rate ?? Money::zero();

            return [
                'product' => $product,
                'packs' => $packs,
                // Negative stock is worth nothing, not a negative asset: the
                // goods are simply not there. It is flagged instead.
                'value' => $packs > 0 ? $cost->times($packs) : Money::zero(),
            ];
        });

        return [
            'rows' => $rows->sortByDesc(fn ($row) => (float) $row['value']->toDecimal())->take(12)->values(),
            'products' => $products->count(),
            'packs' => $rows->sum(fn ($row) => max(0, $row['packs'])),
            'value' => Money::sum($rows->pluck('value')),
            'out' => $rows->filter(fn ($row) => $row['packs'] === 0)->count(),
            'below' => $rows->filter(fn ($row) => $row['packs'] < 0)->count(),
        ];
    }

    /**
     * A payment to this company against what is owed to it.
     *
     * Written onto today's entry, like a payment typed there: the bills
     * themselves are never changed.
     */
    public function pay(
        Request $request,
        Business $business,
        Company $company,
        \App\Domain\Companies\Actions\RecordCompanyPayment $action,
    ): \Illuminate\Http\RedirectResponse {
        $this->authorize('create', [\App\Models\DailyEntry::class, $business]);

        abort_if($company->business_id !== $business->id, 403);

        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^[\d,]*\.?\d{0,2}$/'],
            'reference' => ['nullable', 'string', 'max:60'],
        ], [
            'amount.required' => 'Enter the amount paid.',
            'amount.regex' => 'Enter a plain amount, for example 5000 or 5000.50.',
        ]);

        try {
            $entry = $action->handle(
                $business,
                $company,
                Money::of(str_replace([',', ' '], '', $data['amount']) ?: '0'),
                $request->user(),
                $data['reference'] ?? null,
            );
        } catch (\App\Exceptions\LedgerException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            'Paid Rs. %s to %s. It is on the daily entry for %s.',
            Money::of(str_replace([',', ' '], '', $data['amount']))->format(),
            $company->name,
            $entry->business_date->format('j M Y'),
        ));
    }

    public function show(Request $request, Business $business, Company $company, BalanceService $balances, StockLedger $stock): View
    {
        $this->authorize('configure', $business);

        abort_if($company->business_id !== $business->id, 403);

        /*
         * A correction is a reversal and a replacement. On a statement that
         * reads as the same purchase over and over — a day saved a hundred
         * times shows one invoice two hundred times. The cancelled entry and
         * its reversal are hidden, which leaves the running balance exactly
         * as it was, since the two always cancel. "Show corrections" puts
         * the whole trail back, and the Activity page has it regardless.
         */
        $showReversals = $request->boolean('corrections');

        $entries = $company->account
            ? LedgerEntry::forBusiness($business)
                ->where('account_id', $company->account_id)
                ->unless($showReversals, fn ($q) => $q->whereHas(
                    'transaction',
                    fn ($t) => $t->whereNull('reversal_of_id')->whereNull('reversed_by_id')
                ))
                ->with(['transaction.creator'])
                ->orderBy('business_date')->orderBy('id')->get()
            : collect();

        $running = Money::zero();

        $rows = $entries->map(function (LedgerEntry $entry) use (&$running) {
            // Payables are credit-normal: a credit raises what is owed.
            $running = $running->plus($entry->credit)->minus($entry->debit);

            return ['entry' => $entry, 'running' => $running];
        });

        // Newest first on the screen. The running balance was worked out oldest
        // first, as it has to be, so each row still shows the balance as it
        // stood after that entry.
        $rows = $rows->reverse()->values();

        return view('business.companies.show', [
            'purchases' => app(CompanyPurchases::class)->totals($business)
                ->get($company->id, CompanyPurchases::none()),
            'productCount' => $company->products()->count(),
            // A list read but not yet applied is easy to forget about, so the
            // company's own page says so until it is dealt with.
            'draftImport' => $company->productImports()->drafts()->first(),
            'business' => $business,
            'company' => $company,
            'rows' => $rows,
            'showReversals' => $showReversals,
            'dayClosed' => $business->isDayClosed($business->today()),
            'stock' => $this->stockOf($business, $company, $stock),
            'balance' => $company->account
                ? $balances->asAt($business, $company->account->code)
                : Money::zero(),
        ]);
    }
}
