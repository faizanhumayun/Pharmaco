<?php

namespace App\Http\Controllers\Business;

use App\Domain\Stock\Actions\RecordStockMovements;
use App\Domain\Stock\StockLedger;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The catalogue: what the business's companies sell and at what prices.
 *
 * Reference data, derived entirely from imported price lists. Nothing here
 * touches the ledger — a price is what a company says it charges, not money
 * that has moved — so this screen reads and never posts.
 */
class ProductController extends Controller
{
    public function index(Request $request, Business $business, StockLedger $stock): View
    {
        $this->authorize('viewProducts', $business);

        $companies = Company::forBusiness($business)->orderBy('name')->get();

        /*
         * Sold past the count. A counter may sell what is on the shelf even
         * when the books hold none of it, so this is the owner's working list:
         * every product the books now say there is less than none of, which is
         * either a delivery never entered or a count that needs redoing.
         */
        [$onHand, $looseOnHand] = $stock->heldByProduct($business);
        $belowZero = $onHand->filter(fn ($packs) => (int) $packs < 0)->keys();

        // Something on the shelf: a whole pack, or loose pieces of an open one.
        $inStock = $onHand->filter(fn ($packs, $id) => (int) $packs > 0 || (int) ($looseOnHand[$id] ?? 0) > 0)->keys();

        $products = CompanyProduct::forBusiness($business)
            ->with('company')
            ->when($request->boolean('short'), fn ($q) => $q->whereIn('id', $belowZero->all()))
            ->when($request->boolean('in_stock'), fn ($q) => $q->whereIn('id', $inStock->all()))
            ->search($request->query('q'))
            ->when($request->query('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->when($request->query('form'), fn ($q, $form) => $q->where('dosage_form', $form))
            ->unless($request->boolean('inactive'), fn ($q) => $q->active())
            ->orderBy('brand_name');

        // Every product the filters match, not only this page, for the totals
        // held at the foot of the list.
        $matchingIds = (clone $products)->pluck('id');
        $matchingInStock = $matchingIds->intersect($inStock);

        $products = $products->paginate(50)->withQueryString();

        return view('business.products.index', [
            'business' => $business,
            'companies' => $companies,
            'products' => $products,
            'filters' => [
                'q' => $request->query('q'),
                'company' => $request->query('company'),
                'form' => $request->query('form'),
                'inactive' => $request->boolean('inactive'),
                'in_stock' => $request->boolean('in_stock'),
                'short' => $request->boolean('short'),
            ],
            'forms' => CompanyProduct::forBusiness($business)
                ->whereNotNull('dosage_form')
                ->distinct()
                ->orderBy('dosage_form')
                ->pluck('dosage_form'),
            'total' => CompanyProduct::forBusiness($business)->count(),
            // One query for the page, rather than one per row.
            'onHand' => $onHand,
            'looseOnHand' => $looseOnHand,
            'belowZero' => $belowZero,
            'footer' => [
                'products' => $matchingIds->count(),
                'inStock' => $matchingInStock->count(),
                'packs' => (int) $matchingInStock->sum(fn ($id) => max(0, (int) ($onHand[$id] ?? 0))),
            ],
            // For a product more than one supplier has delivered: the one
            // with the best rate, and how many others there are.
            'suppliers' => app(\App\Domain\Stock\SupplierRates::class)->cheapestFirst($business, $products->getCollection()->modelKeys()),
        ]);
    }

    public function show(Business $business, CompanyProduct $product, StockLedger $stock, \App\Domain\Stock\ExpiryWatch $watch): View
    {
        $this->authorize('viewProducts', $business);

        return view('business.products.show', [
            'business' => $business,
            'product' => $product->load('company.account', 'lastImport.uploader'),

            // Held now, and every movement that made it so.
            'onHand' => $stock->onHand($business, $product),
            // Everyone it has come from, cheapest first.
            'suppliers' => $this->suppliersOf($business, $product),
            // Pieces left in an opened pack, for products sold loose.
            'looseOnHand' => $stock->looseOnHand($business, $product),
            // The batches what is held can still be made of, oldest expiry
            // first, each with how it stands: expired, expiring or fine.
            'batches' => collect($watch->heldBatchesByProduct($business, [$product->id])->get($product->id, []))
                ->map(fn (array $batch) => $batch + [
                    'state' => $watch->stateFor($business, $batch['expires_on']),
                    'value' => $watch->valueAtCost($product, $batch),
                ]),
            // Every change made to the product by hand, newest first.
            'changes' => \Spatie\Activitylog\Models\Activity::query()
                ->where('subject_type', $product->getMorphClass())
                ->where('subject_id', $product->id)
                ->where('event', 'product.updated')
                ->with('causer')
                ->latest('id')
                ->limit(50)
                ->get(),
            // Newest first; each row carries the count as it stood after it.
            'movements' => $stock->history($business, $product)->reverse()->values(),
            // The record of what this product has cost, newest first. Each row
            // is an import that moved a figure; the gaps are the lists that
            // repeated what was already on file.
            // Each price with where it came from: the list (and its company),
            // or the delivery (and its supplier).
            'history' => $product->prices()->with(['import.company', 'source'])->get()
                ->each(fn ($price) => $price->source instanceof \App\Models\Order ? $price->source->loadMissing('company') : null),
        ]);
    }

    /**
     * Everyone this product has come from.
     *
     * A product sits in one company's catalogue, but the goods themselves can
     * arrive from anyone: the same medicine is bought wherever it is in stock
     * or cheapest that week. So the list is read from the deliveries — each
     * company that has delivered it, with what they last charged — and the
     * catalogue company is on it whether or not it has delivered yet.
     *
     * Cheapest first, so the best rate is the first thing read.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function suppliersOf(Business $business, CompanyProduct $product): \Illuminate\Support\Collection
    {
        $seesAccounts = request()->user()->can('configure', $business);
        $balances = app(\App\Domain\Ledger\BalanceService::class);

        $deliveries = \App\Models\StockMovement::forBusiness($business)
            ->where('company_product_id', $product->id)
            ->where('type', StockMovementType::Delivery)
            ->where('source_type', (new \App\Models\Order)->getMorphClass())
            ->with('source.company.account')
            ->orderBy('business_date')
            ->orderBy('id')
            ->get()
            ->filter(fn ($movement) => $movement->source?->company !== null)
            ->groupBy(fn ($movement) => $movement->source->company_id);

        $row = function (\App\Models\Company $company, $movements) use ($product, $business, $seesAccounts, $balances) {
            $last = $movements->last();

            return [
                'company' => $company,
                'catalogue' => $company->id === $product->company_id,
                'deliveries' => $movements->count(),
                'received' => (int) $movements->sum('packs'),
                'last_on' => $last?->business_date,
                'rate' => $last?->unit_cost,
                'link' => $seesAccounts,
                'owed' => $seesAccounts && $company->account !== null
                    ? $balances->directBalance($business, $company->account->code)
                    : null,
            ];
        };

        $rows = $deliveries->map(fn ($movements) => $row($movements->first()->source->company, $movements));

        if ($product->company !== null && ! $rows->has($product->company_id)) {
            $rows->put($product->company_id, $row($product->company, collect()));
        }

        $rows = $rows->sortBy(fn ($r) => $r['rate'] === null ? PHP_FLOAT_MAX : (float) $r['rate']->toDecimal())->values();
        $best = $rows->first(fn ($r) => $r['rate'] !== null);

        return $rows->map(fn ($r) => [...$r, 'best' => $best !== null && $rows->where('rate', '!==', null)->count() > 1 && $r['company']->id === $best['company']->id]);
    }

    public function edit(Business $business, CompanyProduct $product): View
    {
        $this->authorize('editProducts', $business);

        return view('business.products.edit', [
            'business' => $business,
            'product' => $product->load('company'),
            'forms' => \App\Enums\DosageForm::cases(),
            'packTypes' => \App\Enums\PackType::cases(),
        ]);
    }

    public function update(
        Request $request,
        Business $business,
        CompanyProduct $product,
        \App\Domain\Products\Actions\UpdateProduct $action,
    ): RedirectResponse {
        $this->authorize('editProducts', $business);

        $money = ['nullable', 'string', 'regex:/^[\d,]*\.?\d{0,2}$/'];

        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:200'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:60'],
            'strength' => ['nullable', 'string', 'max:80'],
            'dosage_form' => ['nullable', 'string', 'max:40'],
            'pack_size' => ['nullable', 'string', 'max:60'],
            'pack_type' => ['nullable', 'string', 'max:40'],
            'mrp' => $money,
            'trade_price' => $money,
            'purchase_rate' => $money,
            'case_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'units_per_pack' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // A pharmacy has no trade price to set; whatever the form sent for
        // it is ignored rather than trusted.
        if ($business->sellsAtMrp()) {
            unset($data['trade_price']);
        }

        $data['is_active'] = $request->boolean('is_active');
        $data['reorder_level'] = (int) ($data['reorder_level'] ?? 0);
        $data['units_per_pack'] = (int) ($data['units_per_pack'] ?? 1) ?: 1;

        try {
            $action->handle($product, $data, $request->user());
        } catch (\App\Exceptions\LedgerException $e) {
            return back()->withInput()->withErrors(['brand_name' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.products.show', [$business, $product])
            ->with('status', 'Product saved. The changes are listed in the product\'s change history.');
    }

    /**
     * A correction by hand: opening stock, breakage, expiry, a recount.
     *
     * Owner-only, like counting — recording what is there without a delivery
     * behind it is a declaration, not data entry.
     */
    public function adjustStock(
        Request $request,
        Business $business,
        CompanyProduct $product,
        RecordStockMovements $movements,
    ): RedirectResponse {
        $this->authorize('verifyStock', $business);

        $validated = $request->validate([
            'packs' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'type' => ['required', Rule::in([StockMovementType::Opening->value, StockMovementType::Adjustment->value])],
            'note' => ['nullable', 'string', 'max:255'],
            // What goes onto the shelf says when it expires, however it got
            // there. What comes off has no date to give.
            'expires_on' => [Rule::requiredIf((int) $request->input('packs') > 0), 'nullable', 'date_format:Y-m'],
            'batch_no' => ['nullable', 'string', 'max:60'],
        ], [
            'packs.not_in' => 'Enter a quantity other than zero.',
            'expires_on.required' => 'Enter the expiry printed on the pack when adding stock.',
            'expires_on.date_format' => 'The expiry must be a month and a year.',
        ]);

        $adding = (int) $validated['packs'] > 0;
        $expiresOn = $adding && ! empty($validated['expires_on'])
            ? \Illuminate\Support\Carbon::createFromFormat('!Y-m', $validated['expires_on'])->endOfMonth()->toDateString()
            : null;
        $batchNo = $adding ? (trim((string) ($validated['batch_no'] ?? '')) ?: null) : null;

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($movements, $business, $product, $validated, $request, $expiresOn, $batchNo) {
            $movements->adjust(
                $business,
                $product,
                (int) $validated['packs'],
                StockMovementType::from($validated['type']),
                $validated['note'] ?? null,
                $request->user(),
                expiresOn: $expiresOn,
                batchNo: $batchNo,
            );

            // Stock cannot go below zero: you cannot take out more than is there.
            app(StockLedger::class)->assertNotNegative($business, [$product->id]);
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['packs' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s %s %s. Stock is now %d.',
            $validated['packs'] > 0 ? 'Added' : 'Removed',
            number_format(abs((int) $validated['packs'])),
            $business->unit()->many(),
            app(StockLedger::class)->onHand($business, $product),
        ));
    }

    /**
     * The shelf counted batch by batch, from the stock drawer — the same count
     * a company audit makes for one product, approved at once: what is found
     * short is a loss, what is found over goes back into stock, and a batch
     * found that the records did not have goes onto the books under its date.
     */
    public function countStock(
        Request $request,
        Business $business,
        CompanyProduct $product,
        \App\Domain\Stock\Actions\RecordStockCount $counts,
        \App\Domain\Stock\Actions\ResolveStockCount $resolve,
    ): RedirectResponse {
        $this->authorize('verifyStock', $business);

        $validated = $request->validate([
            'batches' => ['required', 'array', 'min:1', 'max:100'],
            'batches.*.batch_no' => ['nullable', 'string', 'max:60'],
            'batches.*.expires_on' => ['nullable', 'date_format:Y-m'],
            'batches.*.packs' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'batches.*.loose' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'batches.*.is_new' => ['nullable', 'boolean'],
            'note' => ['required', 'string', 'max:255'],
        ], [
            'note.required' => 'Say why the stock is being changed.',
            'batches.*.expires_on.date_format' => 'An expiry must be a month and a year.',
        ]);

        // A batch found on the shelf says when it expires, like a delivery.
        foreach ($validated['batches'] as $i => $line) {
            if (! empty($line['is_new']) && ((int) ($line['packs'] ?? 0) + (int) ($line['loose'] ?? 0)) > 0 && empty($line['expires_on'])) {
                return back()->withInput()->withErrors(['count' => 'Enter the expiry printed on each batch found on the shelf.']);
            }
        }

        try {
            $count = \Illuminate\Support\Facades\DB::transaction(function () use ($counts, $resolve, $business, $product, $validated, $request) {
                $count = $counts->handle($business, $product, null, $request->user(), $validated['note'], 0, $validated['batches']);

                return $count->isOpen() ? $resolve->approve($count, $request->user(), $validated['note']) : $count;
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['count' => $e->getMessage()]);
        }

        $stock = app(StockLedger::class);

        return back()->with('status', $count->status === \App\Models\StockCount::AGREED
            ? 'The shelf matches the records. Nothing was changed.'
            : sprintf('Stock counted. It is now %s.', $product->stockLabel($stock->onHand($business, $product), $stock->looseOnHand($business, $product))));
    }
}
