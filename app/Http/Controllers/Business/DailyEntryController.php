<?php

namespace App\Http\Controllers\Business;

use App\Domain\Reporting\ChangeHistory;
use App\Domain\Daily\Actions\AmendDailyEntry;
use App\Domain\Daily\Actions\PostDailyEntry;
use App\Domain\Daily\Actions\ReverseDailyEntry;
use App\Domain\Daily\Actions\SaveDailyEntry;
use App\Domain\Daily\DailyEntryPreview;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\SaveDailyEntryRequest;
use App\Models\Business;
use App\Models\Company;
use App\Models\DailyEntry;
use App\Support\Money;
use App\Models\ExpenseCategory;
use App\Models\Pharmacy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DailyEntryController extends Controller
{
    public function __construct(private readonly DailyEntryPreview $preview) {}

    public function index(Business $business): View
    {
        $this->authorize('viewAny', [DailyEntry::class, $business]);

        return view('business.daily.index', [
            'business' => $business,
            'entries' => DailyEntry::forBusiness($business)
                ->with(['creator', 'poster'])
                ->orderByDesc('business_date')
                ->paginate(30),
        ]);
    }

    public function create(Request $request, Business $business): View|RedirectResponse
    {
        $this->authorize('create', [DailyEntry::class, $business]);

        $date = $request->date('date') ?? $business->today();

        $entry = DailyEntry::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->first();

        // A closed day is out of reach; an open one stays editable whether or
        // not it has been posted.
        if ($entry && ! $request->user()->can('update', $entry)) {
            return redirect()->route('businesses.daily.show', [$business, $entry])
                ->with('status', 'That day is closed, so its figures can no longer be changed.');
        }

        $entry?->load(['purchaseLines.company', 'saleLines.pharmacy', 'expenseLines.category']);

        return view('business.daily.create', [
            'business' => $business,
            'entry' => $entry ?? new DailyEntry(['business_date' => $date]),
            'date' => $date,
            // Suggestions for the invoice rows; typing a new name creates the
            // company and its ledger on save.
            'companies' => Company::forBusiness($business)
                ->active()->orderBy('name')->pluck('name'),
            // The same for the selling side: naming the buyer opens its ledger.
            'pharmacies' => Pharmacy::forBusiness($business)
                ->active()->orderBy('name')->pluck('name'),
            // And the heads a day's spending can be filed under.
            'expenseCategories' => ExpenseCategory::forBusiness($business)
                ->active()->orderBy('sort_order')->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(
        SaveDailyEntryRequest $request,
        Business $business,
        SaveDailyEntry $save,
        AmendDailyEntry $amend,
    ): RedirectResponse {
        $existing = DailyEntry::forBusiness($business)
            ->where('business_date', $request->date('business_date')->toDateString())
            ->first();

        // An already-posted day is amended and posted again in one step: leaving
        // it as a draft would quietly drop it out of every balance.
        if ($existing && ! $existing->isEditable()) {
            $this->authorize('update', $existing);

            try {
                $amend->handle($existing, $request->validated(), $request->user());
            } catch (LedgerException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }

            return redirect()
                ->route('businesses.daily.show', [$business, $existing])
                ->with('status', 'Day updated. The previous postings were reversed and replaced.');
        }

        $entry = $save->handle($business, $request->validated(), $request->user());

        return redirect()
            ->route('businesses.daily.show', [$business, $entry])
            ->with('status', 'Draft saved. Review the resulting position before posting.');
    }

    /**
     * The invoices on this day that money is still owed on, keyed by invoice
     * number, each carrying what the collect dialog needs.
     *
     * A day is read far more often than it is collected against, so the bills
     * and their allocations come back in two queries rather than one per row.
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectable(Business $business, DailyEntry $entry): array
    {
        $outstanding = app(\App\Domain\Market\Outstanding::class);
        $collected = $outstanding->collectedByBill($business);

        $open = $outstanding->bills($business)
            ->each(fn ($bill) => $bill->setAttribute('collected', $collected[$bill->id] ?? Money::zero()))
            ->filter(fn ($bill) => $outstanding->onBill($bill)->isPositive())
            ->groupBy('customer_name');

        $rows = [];

        foreach ($entry->saleLines as $line) {
            if ($line->invoice_no === null || $line->pharmacy === null) {
                continue;
            }

            $theirs = $open[$line->pharmacy->name] ?? collect();

            // This invoice first: it is the one the collector went out for.
            $bills = $theirs
                ->sortByDesc(fn ($bill) => $bill->reference() === $line->invoice_no)
                ->values();

            $mine = $theirs->firstWhere(fn ($bill) => $bill->reference() === $line->invoice_no);

            $rows[$line->invoice_no] = [
                'owed' => $mine !== null ? $outstanding->onBill($mine) : Money::zero(),
                'customer' => [
                    'pharmacy_id' => $line->pharmacy->id,
                    'name' => $line->pharmacy->name,
                    'owed' => (float) Money::sum($bills->map(fn ($b) => $outstanding->onBill($b)))->toDecimal(),
                    'bills' => $bills->map(fn ($bill) => [
                        'id' => $bill->id,
                        'ref' => $bill->reference(),
                        'owed' => (float) $outstanding->onBill($bill)->toDecimal(),
                    ])->all(),
                ],
            ];
        }

        return $rows;
    }

    public function show(Business $business, DailyEntry $entry, ChangeHistory $history): View
    {
        $this->authorize('view', $entry);

        $entry->load([
            'creator', 'poster', 'purchaseLines.company', 'saleLines.pharmacy',
            'expenseLines.category', 'collectionLines.pharmacy', 'collectionLines.allocations.bill',
        ]);

        return view('business.daily.show', [
            'business' => $business,
            'entry' => $entry,
            // What is still owed on each invoice this day sold, so a row that
            // has been paid stops offering to collect it again.
            'collectable' => $this->collectable($business, $entry),
            'today' => $business->today(),
            'dayClosed' => $business->isDayClosed($business->today()),
            'rows' => $this->preview->rows($entry),
            'warnings' => $this->warnings($entry),
            'netBefore' => $this->preview->netPositionBefore($entry),
            'netAfter' => $this->preview->netPositionAfter($entry),
            // Who entered, posted and changed this day, and what they changed.
            'history' => $history->forDailyEntry($entry),
        ]);
    }

    public function post(Request $request, Business $business, DailyEntry $entry, PostDailyEntry $action): RedirectResponse
    {
        $this->authorize('post', $entry);

        try {
            $action->handle($entry, $request->user());
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.daily.show', [$business, $entry])
            ->with('status', 'Day posted. The ledger now reflects this activity.');
    }

    public function reverse(Request $request, Business $business, DailyEntry $entry, ReverseDailyEntry $action): RedirectResponse
    {
        $this->authorize('reverse', $entry);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Say why this day is being reversed. It goes on the permanent record.',
        ]);

        try {
            $action->handle($entry, $validated['reason'], $request->user());
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Day reversed. The original entry and its reversal both remain visible.');
    }

    /**
     * Things that are usually wrong rather than definitely wrong.
     *
     * @return array<int, string>
     */
    private function warnings(DailyEntry $entry): array
    {
        $warnings = [];
        $margin = $entry->marginPercent();

        if ($margin !== null && ($margin > 40 || $margin < 0)) {
            $warnings[] = sprintf(
                'Gross margin of %.1f%% is outside the usual range. This figure drives the stock '
                .'valuation, so it is worth a second look.',
                $margin
            );
        }

        foreach ($this->preview->rows($entry) as $row) {
            if ($row['after']->isNegative()) {
                $warnings[] = "{$row['label']} would go negative ({$row['after']->format()}). "
                    .'That is almost always a data error.';
            }
        }

        if ($entry->owner_drawing->isPositive()) {
            $warnings[] = 'This day includes an owner drawing of '
                .$entry->owner_drawing->format(withCurrency: true)
                .'. It reduces cash and equity, not profit.';
        }

        return $warnings;
    }
}
