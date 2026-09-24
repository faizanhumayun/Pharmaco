<?php

namespace App\Http\Controllers\Business;

use App\Domain\Orders\Actions\RecordDirectDelivery;
use App\Exceptions\LedgerException;
use App\Domain\Products\Actions\ApplyDeliveryPrices;
use App\Enums\BusinessType;
use App\Domain\Products\Actions\RecordProductPrice;
use App\Domain\Products\MatchKey;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Goods that arrived without an order form.
 *
 * The order was placed on the phone, so there is nothing to check off against
 * — but the van still came. This records what was in it.
 */
class DirectDeliveryController extends Controller
{
    public function create(Business $business): View
    {
        $this->authorize('manageOrders', $business);

        return view('business.stock.receive', [
            'business' => $business,
            'companies' => Company::forBusiness($business)->orderBy('name')->get(['id', 'name']),
            // The whole catalogue, narrowed in the browser once a company is
            // picked — the same trick the counter uses, and for the same
            // reason: a delivery is checked in at speed.
            'products' => CompanyProduct::forBusiness($business)
                ->where('is_active', true)
                ->orderBy('brand_name')
                ->get()
                ->map(fn (CompanyProduct $p) => [
                    'id' => $p->id,
                    'company_id' => $p->company_id,
                    'name' => $p->label(),
                    'code' => $p->code,
                    'rate' => (float) ($p->purchase_rate?->toDecimal() ?? 0),
                    // Preloaded so a product already priced is confirmed rather
                    // than typed again; blank only where nothing is on file.
                    'mrp' => $p->mrp?->toDecimal(),
                    'trade' => $p->trade_price?->toDecimal(),
                    'case' => $p->case_size,
                ])
                ->values(),
            'today' => $business->today(),
            // A shop selling to the public must know its own price; a
            // distributor quotes per customer and can fill it in later.
            'sellRequired' => $business->business_type === BusinessType::Pharmacy,
            'sellLabel' => $business->business_type === BusinessType::Pharmacy ? 'Retail' : 'Trade',
            'dayClosed' => $business->isDayClosed($business->today()),
        ]);
    }

    /**
     * A product nobody has imported yet.
     *
     * Companies deliver things that were never on a price list, and the
     * alternative — recording it as a nameless hand-typed line — puts it on the
     * invoice but nowhere in stock, because there is no product to count it
     * against. So it joins the catalogue properly, with the same match key an
     * import would give it, and the next price list recognises it rather than
     * creating a second copy.
     */
    public function storeProduct(Request $request, Business $business, RecordProductPrice $prices): JsonResponse
    {
        $this->authorize('manageOrders', $business);

        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'brand_name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:60'],
            'pack_size' => ['nullable', 'string', 'max:60'],
            'strength' => ['nullable', 'string', 'max:60'],
            'purchase_rate' => ['nullable', 'string'],
            'mrp' => ['nullable', 'string'],
        ]);

        $company = Company::forBusiness($business)->findOrFail($data['company_id']);

        $key = MatchKey::make(
            $data['code'] ?? null,
            $data['brand_name'],
            $data['strength'] ?? null,
            $data['pack_size'] ?? null,
        );

        // Already there under another name on screen — hand back the one that
        // exists rather than making a second.
        $product = CompanyProduct::forBusiness($business)
            ->where('company_id', $company->id)
            ->where('match_key', $key)
            ->first();

        $product ??= CompanyProduct::create([
            'business_id' => $business->id,
            'company_id' => $company->id,
            'match_key' => $key,
            'code' => $data['code'] ?? null,
            'brand_name' => $data['brand_name'],
            'strength' => $data['strength'] ?? null,
            'pack_size' => $data['pack_size'] ?? null,
            'purchase_rate' => $data['purchase_rate'] ?: null,
            'mrp' => $data['mrp'] ?: null,
            'is_active' => true,
        ]);

        // What it costs today, on the record from the moment it exists.
        $prices->handle($product, $request->user());

        return response()->json([
            'id' => $product->id,
            'company_id' => $product->company_id,
            'name' => $product->label(),
            'code' => $product->code,
            'rate' => (float) ($product->purchase_rate?->toDecimal() ?? 0),
            'case' => $product->case_size,
        ]);
    }

    public function store(
        Request $request,
        Business $business,
        RecordDirectDelivery $action,
        ApplyDeliveryPrices $prices,
    ): RedirectResponse
    {
        $this->authorize('manageOrders', $business);

        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'invoice_no' => ['nullable', 'string', 'max:60'],
            'paid' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.company_product_id' => ['required', 'integer'],
            'lines.*.packs' => ['required', 'integer', 'min:1'],
            'lines.*.rate' => ['nullable', 'string'],
            'lines.*.mrp' => ['nullable', 'string'],
            // A shop that sells to the public has to know what it sells at; a
            // distributor quotes per customer and may leave it for later.
            'lines.*.trade' => [$business->business_type === BusinessType::Pharmacy ? 'required' : 'nullable', 'string'],
        ], [
            'lines.*.trade.required' => 'Every line needs the price you will sell it at.',
        ]);

        try {
            /*
             * Before the delivery, not after: receiving writes the product's
             * price history, so every figure has to be on the product by then
             * or the row records a price that was already out of date.
             */
            $prices->handle(
                $business,
                collect($data['lines'])->keyBy('company_product_id')
                    ->map(fn ($line) => ['mrp' => $line['mrp'] ?? null, 'trade' => $line['trade'] ?? null])
                    ->all(),
                $request->user(),
            );

            $order = $action->handle($business, $data, $request->user());
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.orders.show', [$business, $order])
            ->with('status', "Delivery recorded as {$order->reference} — {$order->receivedTotal()->format()} into stock.");
    }
}
