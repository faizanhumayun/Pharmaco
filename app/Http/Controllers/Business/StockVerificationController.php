<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Domain\Stock\Actions\RecordStockVerification;
use App\Domain\Stock\GoodsReceived;
use App\Domain\Stock\StockConfidence;
use App\Domain\Stock\StockLedger;
use App\Enums\AccountCode;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\StockVerification;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class StockVerificationController extends Controller
{
    public function index(
        Request $request,
        Business $business,
        BalanceService $balances,
        StockConfidence $confidence,
        GoodsReceived $received,
        StockLedger $stock,
    ): View {
        $this->authorize('verifyStock', $business);

        $period = $received->period($business, $request->query('period'));
        $held = $received->onHand($business, $stock, $period['from']);

        /*
         * What is held, a page at a time. The totals below stay whole-business
         * figures — a page of a list is not a smaller business — but sending
         * every product at once is what made this page slow to open.
         */
        $search = trim((string) $request->string('q'));

        // The companies whose goods are held, for the filter: no point
        // offering one with nothing on the shelf.
        $companies = $held->map(fn (array $row) => $row['product']->company)->filter()
            ->unique('id')->sortBy(fn ($c) => mb_strtolower($c->name))->values();
        $company = $companies->firstWhere('id', $request->integer('company'));

        // Filtered to a company, the figures at the top are that company's:
        // its goods held, its deliveries, its goods sold.
        $scope = $company ? $held->filter(fn (array $row) => $row['product']->company_id === $company->id)->values() : $held;
        $deliveries = $received->deliveries($business, $period['from'], $company?->id);
        $productIds = $company
            ? \App\Models\CompanyProduct::forBusiness($business)->where('company_id', $company->id)->pluck('id')->all()
            : null;

        $matching = $held
            ->when($company, fn ($rows) => $rows->filter(fn (array $row) => $row['product']->company_id === $company->id))
            ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row) => str_contains(
                mb_strtolower($row['product']->label() . ' ' . $row['product']->generic_name . ' ' . $row['product']->code),
                mb_strtolower($search)
            )))
            ->values();

        $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        $onHand = new \Illuminate\Pagination\LengthAwarePaginator(
            $matching->forPage($page, 50)->values(),
            $matching->count(),
            50,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('business.stock.index', [
            'business' => $business,
            'bookValue' => $balances->asAt($business, AccountCode::Stock),
            'confidence' => $confidence->for($business),
            'verifications' => StockVerification::forBusiness($business)
                ->with('verifier')
                ->orderByDesc('business_date')
                ->paginate(20),

            // What has come in. Deliveries are goods arriving; nothing records
            // goods leaving per item, so this is received, never held.
            'period' => $period,
            'periodKey' => $request->query('period', 'all'),
            'deliveries' => $deliveries,
            'receivedValue' => $received->value($deliveries),
            'soldValue' => $received->soldValue($business, $period['from'], $productIds),

            // What is held, product by product.
            'onHand' => $onHand,
            'search' => $search,
            'companies' => $companies,
            'company' => $company,
            // What the filtered list is worth, said under the bar.
            'matchingValue' => Money::sum($matching->pluck('value')->filter()),
            'productsHeld' => $scope->count(),
            'packsHeld' => $scope->sum('packs'),
            'heldValue' => Money::sum($scope->pluck('value')->filter()),
        ]);
    }

    public function store(Request $request, Business $business, RecordStockVerification $action): RedirectResponse
    {
        $this->authorize('verifyStock', $business);

        $validated = $request->validate([
            'business_date' => ['required', 'date', 'before_or_equal:'.$business->today()->toDateString()],
            'counted_value' => ['required', 'string', 'regex:/^[\d,]*\.?\d*$/'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $action->handle(
                $business,
                Carbon::parse($validated['business_date']),
                Money::of(str_replace(',', '', $validated['counted_value'])),
                $validated['reason'] ?? null,
                $request->user(),
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['counted_value' => $e->getMessage()]);
        }

        return back()->with('status', 'Stock verified. Any difference has been posted to profit and loss.');
    }
}
