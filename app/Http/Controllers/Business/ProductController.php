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

        $products = CompanyProduct::forBusiness($business)
            ->with('company')
            ->search($request->query('q'))
            ->when($request->query('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->when($request->query('form'), fn ($q, $form) => $q->where('dosage_form', $form))
            ->unless($request->boolean('inactive'), fn ($q) => $q->active())
            ->orderBy('brand_name')
            ->paginate(50)
            ->withQueryString();

        return view('business.products.index', [
            'business' => $business,
            'companies' => $companies,
            'products' => $products,
            'filters' => [
                'q' => $request->query('q'),
                'company' => $request->query('company'),
                'form' => $request->query('form'),
                'inactive' => $request->boolean('inactive'),
            ],
            'forms' => CompanyProduct::forBusiness($business)
                ->whereNotNull('dosage_form')
                ->distinct()
                ->orderBy('dosage_form')
                ->pluck('dosage_form'),
            'total' => CompanyProduct::forBusiness($business)->count(),
            // One query for the page, rather than one per row.
            'onHand' => $stock->onHandByProduct($business),
        ]);
    }

    public function show(Business $business, CompanyProduct $product, StockLedger $stock): View
    {
        $this->authorize('viewProducts', $business);

        return view('business.products.show', [
            'business' => $business,
            'product' => $product->load('company', 'lastImport.uploader'),

            // Held now, and every movement that made it so.
            'onHand' => $stock->onHand($business, $product),
            'movements' => $stock->history($business, $product),
            // The record of what this product has cost, newest first. Each row
            // is an import that moved a figure; the gaps are the lists that
            // repeated what was already on file.
            'history' => $product->prices()->with('import')->get(),
        ]);
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
        ], [
            'packs.not_in' => 'An adjustment of nothing is not an adjustment.',
        ]);

        try {
            $movements->adjust(
                $business,
                $product,
                (int) $validated['packs'],
                StockMovementType::from($validated['type']),
                $validated['note'] ?? null,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['packs' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s %s packs. Stock is now %d.',
            $validated['packs'] > 0 ? 'Added' : 'Removed',
            number_format(abs((int) $validated['packs'])),
            app(StockLedger::class)->onHand($business, $product),
        ));
    }
}
