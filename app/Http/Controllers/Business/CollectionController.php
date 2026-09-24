<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Domain\Market\Actions\RecordCollection;
use App\Domain\Market\Outstanding;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DailyEntry;
use App\Models\Pharmacy;
use App\Models\PosBill;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What the market still owes, and taking money against it.
 *
 * The van goes out with the goods and comes back with cash — sometimes the
 * bill's figure, often less, sometimes more because old dues came with it.
 * This is where that lands.
 */
class CollectionController extends Controller
{
    public function index(
        Request $request,
        Business $business,
        Outstanding $outstanding,
        BalanceService $balances,
    ): View {
        $this->authorize('viewAny', [DailyEntry::class, $business]);

        $today = $business->today();

        // One query for every allocation on the page, rather than one per bill.
        $collected = $outstanding->collectedByBill($business);

        $bills = $outstanding->bills($business)
            ->each(fn (PosBill $bill) => $bill->setAttribute(
                'collected', $collected[$bill->id] ?? Money::zero()
            ))
            ->filter(fn (PosBill $bill) => $outstanding->onBill($bill)->isPositive())
            ->values();

        $search = trim((string) $request->query('q'));

        if ($search !== '') {
            $bills = $bills->filter(
                fn (PosBill $bill) => str_contains(mb_strtolower((string) $bill->customer_name), mb_strtolower($search))
                    || str_contains(mb_strtolower($bill->reference()), mb_strtolower($search))
            )->values();
        }

        /*
         * Grouped by who owes it, because collection is a round of customers
         * rather than a list of documents — the van visits a pharmacy, not an
         * invoice.
         */
        $customers = Pharmacy::forBusiness($business)->get()->keyBy(fn ($p) => mb_strtolower($p->name));

        $rounds = $bills->groupBy('customer_name')->map(function ($theirs, $name) use ($outstanding, $today, $customers) {
            $oldest = $theirs->min(fn (PosBill $bill) => $outstanding->ageOf($bill, $today));

            return [
                'name' => $name,
                'pharmacy' => $customers[mb_strtolower((string) $name)] ?? null,
                'bills' => $theirs->map(fn (PosBill $bill) => [
                    'bill' => $bill,
                    'owed' => $outstanding->onBill($bill),
                    'age' => $outstanding->ageOf($bill, $today),
                ])->values(),
                'owed' => Money::sum($theirs->map(fn (PosBill $bill) => $outstanding->onBill($bill))),
                'waiting' => $theirs->max(fn (PosBill $bill) => $outstanding->ageOf($bill, $today)),
                'band' => $outstanding->bandFor($theirs->max(fn (PosBill $b) => $outstanding->ageOf($b, $today))),
            ];
        })->sortByDesc('waiting')->values();

        return view('business.collections.index', [
            'business' => $business,
            'rounds' => $rounds,
            'search' => $search,
            'today' => $today,
            'total' => Money::sum($rounds->pluck('owed')),
            // What the ledger says the whole market owes, which includes the
            // opening receivable that never belonged to a bill.
            'marketTotal' => $balances->asAt($business, \App\Enums\AccountCode::MarketReceivables),
            'dayClosed' => $business->isDayClosed($today),
        ]);
    }

    public function store(Request $request, Business $business, RecordCollection $action): RedirectResponse
    {
        $this->authorize('create', [DailyEntry::class, $business]);

        $data = $request->validate([
            'pharmacy_id' => ['required', 'integer'],
            'amount' => ['required', 'string'],
            'pos_bill_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $pharmacy = Pharmacy::forBusiness($business)->findOrFail($data['pharmacy_id']);
        $bill = $data['pos_bill_id'] ?? null
            ? PosBill::forBusiness($business)->find($data['pos_bill_id'])
            : null;

        try {
            $entry = $action->handle(
                $business,
                $pharmacy,
                Money::of($data['amount']),
                $request->user(),
                against: $bill,
                note: $data['note'] ?? null,
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        // Back where the collecting was done — the round, or the day whose
        // invoice it was — rather than always to the list.
        return back()
            ->with('status', "Collected Rs. {$data['amount']} from {$pharmacy->name} — on the entry for {$entry->business_date->format('j M Y')}.");
    }
}
