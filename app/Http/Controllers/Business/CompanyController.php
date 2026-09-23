<?php

namespace App\Http\Controllers\Business;

use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Companies\CompanyPurchases;
use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
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
        $totals = $purchases->totals($business);

        $total = $balances->asAt($business, AccountCode::CompanyPayables);
        $sum = Money::sum($companies->map(
            fn (Company $c) => $c->account ? $balances->asAt($business, $c->account->code) : Money::zero()
        ));

        $owed = $companies->mapWithKeys(fn (Company $c) => [
            $c->id => $c->account ? $balances->asAt($business, $c->account->code) : Money::zero(),
        ]);

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

        return view('business.companies.index', [
            'business' => $business,
            'companies' => $companies,
            'search' => $search,
            'status' => $status,
            'owing' => $request->boolean('owing'),
            'sort' => $sort,
            'shownOwed' => Money::sum($companies->map(fn (Company $c) => $owed[$c->id])),
            'balances' => $companies->mapWithKeys(fn (Company $c) => [
                $c->id => $c->account ? $balances->asAt($business, $c->account->code) : Money::zero(),
            ]),
            'purchases' => $companies->mapWithKeys(fn (Company $c) => [
                $c->id => $totals->get($c->id, CompanyPurchases::none()),
            ]),
            'billedTotal' => Money::sum($totals->pluck('billed')),
            'paidTotal' => Money::sum($totals->pluck('paid')),
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

    public function show(Business $business, Company $company, BalanceService $balances): View
    {
        $this->authorize('configure', $business);

        abort_if($company->business_id !== $business->id, 403);

        $entries = $company->account
            ? LedgerEntry::forBusiness($business)
                ->where('account_id', $company->account_id)
                ->with(['transaction.creator'])
                ->orderBy('business_date')->orderBy('id')->get()
            : collect();

        $running = Money::zero();

        $rows = $entries->map(function (LedgerEntry $entry) use (&$running) {
            // Payables are credit-normal: a credit raises what is owed.
            $running = $running->plus($entry->credit)->minus($entry->debit);

            return ['entry' => $entry, 'running' => $running];
        });

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
            'balance' => $company->account
                ? $balances->asAt($business, $company->account->code)
                : Money::zero(),
        ]);
    }
}
