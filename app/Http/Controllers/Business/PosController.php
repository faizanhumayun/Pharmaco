<?php

namespace App\Http\Controllers\Business;

use App\Domain\Pos\Actions\RecordPosSale;
use App\Domain\Stock\StockLedger;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StorePosSaleRequest;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\PosBill;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The counter.
 *
 * Everything the till needs is sent with the page: searching 600 products has
 * to answer as fast as a barcode scanner types, and the counter cannot wait on
 * a round trip for each keystroke.
 */
class PosController extends Controller
{
    public function index(Business $business, StockLedger $stock): View
    {
        $this->authorize('sellAtPos', $business);

        $onHand = $stock->onHandByProduct($business);

        $products = CompanyProduct::forBusiness($business)
            ->where('is_active', true)
            ->with('company:id,name')
            ->orderBy('brand_name')
            ->get()
            ->map(fn (CompanyProduct $p) => [
                'id' => $p->id,
                'name' => $p->label(),
                'code' => $p->code,
                'company' => $p->company?->name,
                'price' => (float) ($p->mrp?->toDecimal() ?? 0),
                'cost' => (float) ($p->purchase_rate?->toDecimal() ?? 0),
                'stock' => (int) ($onHand[$p->id] ?? 0),
            ])
            ->values();

        return view('business.pos.index', [
            // Shown on the screen before the sale is saved, so the counter and
            // the customer are looking at the same bill number.
            'billReference' => 'B-' . str_pad((string) ((int) PosBill::forBusiness($business)->max('bill_no') + 1), 5, '0', STR_PAD_LEFT),
            'business' => $business,
            'products' => $products,
            'today' => $business->today(),
            'dayClosed' => $business->isDayClosed($business->today()),
            'recent' => PosBill::forBusiness($business)
                ->with('creator')
                ->latest('id')
                ->limit(10)
                ->get(),
            'soldToday' => PosBill::forBusiness($business)
                ->whereDate('business_date', $business->today()->toDateString())
                ->get(),
        ]);
    }

    public function store(StorePosSaleRequest $request, Business $business, RecordPosSale $action): RedirectResponse
    {
        try {
            $bill = $action->handle(
                $business,
                $request->input('items'),
                Money::of($request->string('received')->toString() ?: '0'),
                $request->input('customer_name'),
                $request->input('note'),
                $request->user(),
                discount: Money::of($request->string('discount')->toString() ?: '0'),
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.pos.receipt', [$business, $bill])
            ->with('status', "Bill {$bill->reference()} saved — Rs. {$bill->total->format()}.");
    }

    /** The receipt, and the page that prints it. */
    public function receipt(Business $business, PosBill $posBill): View
    {
        $this->authorize('sellAtPos', $business);

        return view('business.pos.receipt', [
            'business' => $business,
            'bill' => $posBill->load(['lines', 'creator']),
        ]);
    }
}
