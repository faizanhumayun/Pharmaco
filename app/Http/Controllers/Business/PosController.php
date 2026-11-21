<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Domain\Pos\Actions\RecordPosSale;
use App\Domain\Stock\StockLedger;
use App\Enums\ReceiptFormat;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StorePosSaleRequest;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\PosBill;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(
        Request $request,
        Business $business,
        StockLedger $stock,
        BalanceService $balances,
        \App\Domain\Stock\ExpiryWatch $expiry,
    ): View|\Illuminate\Http\RedirectResponse {
        $this->authorize('sellAtPos', $business);

        /*
         * Correcting a saved bill happens on the same screen it was rung up
         * on: ?edit= loads the bill back onto the till. A bill that can no
         * longer be changed goes to its own page, which says why.
         */
        $editing = null;

        if ($request->filled('edit')) {
            $this->authorize('editPosBills', $business);

            $editing = PosBill::forBusiness($business)->with(['lines', 'returns', 'pharmacy'])->findOrFail((int) $request->query('edit'));

            if ($business->isDayClosed($editing->business_date) || $editing->returns->isNotEmpty()) {
                return redirect()->route('businesses.pos.bills.edit', [$business, $editing]);
            }
        }

        // What the bill being corrected took is free to it again, in pieces.
        $own = $editing
            ? $editing->lines->groupBy('company_product_id')->map(fn ($lines) => $lines->sum(fn ($l) => $l->pieces()))
            : collect();

        $onHand = $stock->onHandByProduct($business);
        $looseOnHand = $stock->looseByProduct($business);

        // The soonest date still on the shelf for each product, so short-dated
        // goods can be pulled off it rather than sold by mistake.
        $soonest = $expiry->soonestByProduct($business);

        $products = CompanyProduct::forBusiness($business)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $own->keys()))
            ->with('company:id,name')
            ->orderBy('brand_name')
            ->get()
            ->map(fn (CompanyProduct $p) => [
                'id' => $p->id,
                'name' => $p->label(),
                // The list shows the name and, beside it, the type in a chip
                // of its own. 'name' keeps the type in it: that is what goes
                // on the bill and what the search reads.
                'short' => \App\Domain\Products\ProductLabel::make($p->brand_name, $p->strength),
                'form' => ($form = trim((string) $p->dosage_form)) === ''
                    ? null
                    : ($p->dosageForm()?->label() ?? \Illuminate\Support\Str::title(mb_strtolower($form))),
                'code' => $p->code,
                'company' => $p->company?->name,
                // What this business sells at: the MRP for a pharmacy, the
                // trade price for a distributor (see Business::sellsAtMrp()).
                'price' => (float) ($p->sellingPriceFor($business)?->toDecimal() ?? 0),
                'trade' => (float) ($p->trade_price?->toDecimal() ?? 0),
                'mrp' => (float) ($p->mrp?->toDecimal() ?? 0),
                'cost' => (float) ($p->purchase_rate?->toDecimal() ?? 0),
                'stock' => (int) ($onHand[$p->id] ?? 0),
                // Pieces in a pack, and the opened pack's pieces on the shelf.
                'per' => max(1, (int) $p->units_per_pack),
                'pack' => trim((string) $p->pack_size),
                'loose_stock' => (int) ($looseOnHand[$p->id] ?? 0),
                'expires_on' => ($e = $soonest[$p->id] ?? null) ? $e['expires_on']->toDateString() : null,
                'expires_label' => $e ? $e['expires_on']->format('M Y') : null,
                'expiry_state' => $expiry->stateFor($business, $e['expires_on'] ?? null),
            ])
            ->map(function (array $p) use ($own) {
                if ($back = (int) ($own[$p['id']] ?? 0)) {
                    $pieces = $p['stock'] * $p['per'] + $p['loose_stock'] + $back;
                    $p['stock'] = intdiv($pieces, $p['per']);
                    $p['loose_stock'] = $pieces % $p['per'];
                }

                return $p;
            })
            ->values();

        /*
         * Who the bill is for. A distributor sells to pharmacies it knows by
         * name, and what each already owes decides whether more credit is
         * wise — so the balance comes with the name. One query for all of
         * them, not one per customer.
         */
        $owed = \App\Models\LedgerEntry::query()
            ->where('business_id', $business->id)
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->groupBy('account_id')
            ->pluck('balance', 'account_id');

        $customers = \App\Models\Pharmacy::forBusiness($business)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'area' => $p->area,
                'phone' => $p->phone,
                'owes' => (float) ($owed[$p->account_id] ?? 0),
            ])
            ->values();

        return view('business.pos.index', [
            'editing' => $editing,
            // The bill as it stands, for the till to start from.
            'editingLines' => $editing?->lines->map(fn ($l) => [
                'id' => $l->company_product_id,
                'qty' => (int) $l->quantity,
                'loose' => (int) $l->loose_quantity,
                'price' => (float) $l->unit_price->toDecimal(),
            ])->values() ?? collect(),
            'customers' => $customers,
            // Ways of paying other than cash that this business has switched on.
            'methods' => \App\Models\BusinessPaymentMethod::forBusiness($business)->active()->with('method')->get(),
            'saved' => $this->justSaved($business, $balances),
            'format' => $business->receiptFormat(),
            // Shown on the screen before the sale is saved, so the counter and
            // the customer are looking at the same bill number.
            'billReference' => PosBill::referenceFor($business->today(), PosBill::nextDailyNo($business, $business->today())),
            'business' => $business,
            'products' => $products,
            'today' => $business->today(),
            // A bill being corrected belongs to its own day, which is open
            // or it would not have loaded.
            'dayClosed' => $editing ? false : $business->isDayClosed($business->today()),
            'expiryMonths' => $business->expiryAlertMonths(),
            'recent' => PosBill::forBusiness($business)
                ->with('creator')
                ->latest('id')
                ->limit(10)
                ->get(),
            // Today's bills, newest first — the list behind the header figure,
            // and what the counter reaches for when a customer comes back.
            'soldToday' => $soldToday = PosBill::forBusiness($business)
                ->whereDate('business_date', $business->today()->toDateString())
                ->with('creator')
                ->withCount('lines')
                ->orderByDesc('id')
                ->get(),
            'statementFor' => $this->statementsFor($request, $business, $soldToday),
        ]);
    }

    /**
     * Which of today's buyers have a statement to open, by name.
     *
     * A bill carries the customer's name, not their id, so the name is looked
     * up once for the whole list. Empty for anyone who cannot read statements:
     * the history offers only what the statement page will let them see.
     *
     * @return array<string, int>
     */
    private function statementsFor(Request $request, Business $business, $bills): array
    {
        if (! $request->user()->can('configure', $business)) {
            return [];
        }

        $names = $bills->pluck('customer_name')->filter()->unique()->values();

        if ($names->isEmpty()) {
            return [];
        }

        return \App\Models\Pharmacy::forBusiness($business)
            ->whereIn('name', $names)
            ->pluck('id', 'name')
            ->all();
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
                shownAs: [
                    'net' => ReceiptFormat::NET_BILL_AVAILABLE && $request->boolean('net'),
                    'print' => $request->boolean('preview'),
                    'balance' => $request->boolean('balance'),
                ],
                via: $request->filled('via')
                    ? \App\Models\BusinessPaymentMethod::forBusiness($business)->active()->find($request->integer('via'))
                    : null,
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        /*
         * Back to the counter, not off to the receipt: the next customer is
         * already waiting. What the operator wanted to see after a sale — the
         * bill to print, the customer's account, or neither — is carried in the
         * flash and shown there without leaving the till.
         */
        return redirect()
            ->route('businesses.pos.index', $business)
            ->with('status', "Bill {$bill->reference()} saved — Rs. {$bill->total->format()}.")
            ->with('saved_bill', [
                'id' => $bill->id,
                'print' => $request->boolean('preview'),
                'balance' => $request->boolean('balance'),
                'net' => ReceiptFormat::NET_BILL_AVAILABLE && $request->boolean('net'),
            ]);
    }

    /** The receipt, and the page that prints it. */
    /**
     * What to show about the bill just saved, if anything was asked for.
     *
     * Both switches are off to begin with, and with both off this returns null
     * — a till that stops to show something after every sale is a till that
     * slows the queue down. The flash is read once and gone.
     */
    private function justSaved(Business $business, BalanceService $balances): ?array
    {
        $flash = session('saved_bill');

        if (! is_array($flash) || (! ($flash['print'] ?? false) && ! ($flash['balance'] ?? false))) {
            return null;
        }

        $bill = PosBill::forBusiness($business)
            ->with(['lines', 'creator'])
            ->find($flash['id'] ?? 0);

        if ($bill === null) {
            return null;
        }

        return [
            'bill' => $bill,
            'print' => (bool) $flash['print'],
            // Checked here too: a bill saved a moment before the net bill was
            // withdrawn would otherwise still be shown as one.
            'net' => ReceiptFormat::NET_BILL_AVAILABLE && (bool) ($flash['net'] ?? false),
            'account' => ($flash['balance'] ?? false) ? $this->accountFor($business, $bill, $balances) : null,
        ];
    }

    /**
     * Where this customer's account stands, and how this bill moved it.
     *
     * The ledger only knows about days that have been posted, so a bill rung
     * up against a day still in draft is not in it yet. Saying "owes X" while
     * quietly leaving out today's credit would be the kind of half-true figure
     * this system exists to avoid, so both parts are shown and named.
     */
    private function accountFor(Business $business, PosBill $bill, BalanceService $balances): ?array
    {
        if ($bill->customer_name === null) {
            return null;
        }

        $customer = \App\Models\Pharmacy::forBusiness($business)
            ->where('name', $bill->customer_name)
            ->with('account')
            ->first();

        if ($customer?->account === null) {
            return null;
        }

        $posted = $balances->directBalance($business, $customer->account->code);

        // Bills on days that have not been posted — this one included, when
        // the day is still a draft.
        $unposted = Money::zero();

        foreach (PosBill::forBusiness($business)
            ->where('customer_name', $bill->customer_name)
            ->with('dailyEntry')
            ->get() as $other) {
            if ($other->dailyEntry !== null && ! $other->dailyEntry->status->isEditable()) {
                continue;
            }

            $unposted = $unposted->plus($other->credit());
        }

        $owes = $posted->plus($unposted);

        return [
            'customer' => $customer,
            'posted' => $posted,
            'unposted' => $unposted,
            'owes' => $owes,
            // What the account stood at before this bill was rung up.
            'before' => $owes->minus($bill->credit()),
        ];
    }

    public function receipt(
        Request $request,
        Business $business,
        PosBill $posBill,
        BalanceService $balances,
    ): View {
        $this->authorize('sellAtPos', $business);

        /*
         * The business's own paper unless this print asks for the other one.
         * A borrowed format is not remembered: the setting belongs on the
         * business, not in whichever link someone last followed.
         */
        $format = ReceiptFormat::tryFrom((string) $request->query('format'))
            ?? $business->receiptFormat();

        // The same bill, quoted before the trade discount. A view, not a
        // different sale: the net at its foot is this bill's own total.
        /*
         * The bill it was rung up as, unless this link asks for the other one.
         * A net bill reopened as a plain one is a different document, and the
         * customer is holding the first.
         */
        $net = ReceiptFormat::NET_BILL_AVAILABLE
            && ($request->has('net') ? $request->boolean('net') : $posBill->as_net_bill);

        // A sheet is addressed to somebody, and says what their account stands
        // at. A slip is not, so neither is looked up for one.
        $customer = null;
        $owed = null;

        if (($format === ReceiptFormat::A4 || $net) && $posBill->customer_name !== null) {
            $customer = \App\Models\Pharmacy::forBusiness($business)
                ->where('name', $posBill->customer_name)
                ->with('account')
                ->first();

            if ($customer?->account !== null) {
                $owed = $balances->directBalance($business, $customer->account->code);
            }
        }

        return view('business.pos.receipt', [
            'business' => $business,
            // The product with each line: the receipt names what a loose piece is.
            'bill' => $posBill->load(['lines.product', 'creator']),
            'format' => $format,
            'net' => $net,
            'customer' => $customer,
            'owed' => $owed,
        ]);
    }
}
