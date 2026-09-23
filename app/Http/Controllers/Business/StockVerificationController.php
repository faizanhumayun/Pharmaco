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
        $deliveries = $received->deliveries($business, $period['from']);
        $onHand = $received->onHand($business, $stock, $period['from']);

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

            // What is held, product by product.
            'onHand' => $onHand,
            'packsHeld' => $onHand->sum('packs'),
            'heldValue' => Money::sum($onHand->pluck('value')->filter()),
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
