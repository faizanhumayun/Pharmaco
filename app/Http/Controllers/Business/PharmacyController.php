<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Domain\Pharmacies\Actions\CreatePharmacy;
use App\Enums\AccountCode;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\LedgerEntry;
use App\Models\Pharmacy;
use App\Support\Money;
use Illuminate\Contracts\View\View;
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
    public function index(Business $business, BalanceService $balances): View
    {
        $this->authorize('configure', $business);

        $pharmacies = Pharmacy::forBusiness($business)->with('account')->orderBy('name')->get();

        $total = $balances->asAt($business, AccountCode::MarketReceivables);
        $sum = Money::sum($pharmacies->map(
            fn (Pharmacy $p) => $p->account ? $balances->asAt($business, $p->account->code) : Money::zero()
        ));

        return view('business.pharmacies.index', [
            'business' => $business,
            'pharmacies' => $pharmacies,
            'balances' => $pharmacies->mapWithKeys(fn (Pharmacy $p) => [
                $p->id => $p->account ? $balances->asAt($business, $p->account->code) : Money::zero(),
            ]),
            'total' => $total,
            // Stated as a fact, not maintained as a figure.
            'unallocated' => $total->minus($sum),
            'reconciles' => $balances->controlReconciles($business, AccountCode::MarketReceivables),
        ]);
    }

    public function store(Request $request, Business $business, CreatePharmacy $action): RedirectResponse
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

        return back()->with('status', "{$pharmacy->name} added with its own ledger ({$pharmacy->account->code}).");
    }

    public function show(Business $business, Pharmacy $pharmacy, BalanceService $balances): View
    {
        $this->authorize('configure', $business);

        abort_if($pharmacy->business_id !== $business->id, 403);

        $entries = $pharmacy->account
            ? LedgerEntry::forBusiness($business)
                ->where('account_id', $pharmacy->account_id)
                ->with(['transaction.creator'])
                ->orderBy('business_date')->orderBy('id')->get()
            : collect();

        $running = Money::zero();

        $rows = $entries->map(function (LedgerEntry $entry) use (&$running) {
            // Receivables are debit-normal: a debit raises what is owed to us.
            $running = $running->plus($entry->debit)->minus($entry->credit);

            return ['entry' => $entry, 'running' => $running];
        });

        return view('business.pharmacies.show', [
            'business' => $business,
            'pharmacy' => $pharmacy,
            'rows' => $rows,
            'balance' => $pharmacy->account
                ? $balances->asAt($business, $pharmacy->account->code)
                : Money::zero(),
        ]);
    }
}
