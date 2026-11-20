<?php

namespace App\Http\Controllers\Business;

use App\Domain\Market\Outstanding;
use App\Domain\Pos\Actions\EditPosBill;
use App\Domain\Pos\Actions\RecordPosReturn;
use App\Domain\Stock\StockLedger;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessPaymentMethod;
use App\Models\CompanyProduct;
use App\Models\Pharmacy;
use App\Models\PosBill;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * What happens to a bill after it is saved: corrections and returns.
 *
 * Authorizes, delegates to an action, redirects. The rules — what can still be
 * edited, how a return is settled — live in the actions.
 */
class PosBillController extends Controller
{
    /** The bill to read: what is on it and its history. Corrections are made at the till. */
    public function edit(Business $business, PosBill $posBill, Outstanding $outstanding, \App\Domain\Ledger\BalanceService $balances): View
    {
        $this->authorize('sellAtPos', $business);

        $posBill->load(['lines', 'creator', 'paymentMethod.method', 'returns.lines', 'returns.creator', 'revisions.editor']);

        $canEdit = request()->user()->can('editPosBills', $business);
        $blocked = match (true) {
            $business->isDayClosed($posBill->business_date) => $posBill->business_date->format('D d M Y').' is closed. Reopen it on its closing page to change this bill.',
            $posBill->returns->isNotEmpty() => 'Goods have been returned against this bill, so it can no longer be edited.',
            default => null,
        };

        // The customer's other bills, newest first, when the bill has a
        // customer on it: by their account where there is one, by the name
        // typed otherwise. A walk-in bill has no history to show.
        $customerBills = collect();

        if ($posBill->pharmacy_id !== null || filled($posBill->customer_name)) {
            $customerBills = PosBill::forBusiness($business)
                ->when(
                    $posBill->pharmacy_id !== null,
                    fn ($q) => $q->where('pharmacy_id', $posBill->pharmacy_id),
                    fn ($q) => $q->whereNull('pharmacy_id')->where('customer_name', $posBill->customer_name),
                )
                ->with('returns')
                ->withCount('lines')
                ->orderByDesc('business_date')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->each(fn (PosBill $b) => $b->setAttribute('owing', $outstanding->onBill($b)));
        }

        // The bill is drawn the way it prints: on this business's paper, as
        // the bill it was rung up as. A sheet is addressed to somebody and
        // says what their account stands at; a till slip is not.
        $format = $business->receiptFormat();
        $net = \App\Enums\ReceiptFormat::NET_BILL_AVAILABLE && $posBill->as_net_bill;
        $customer = null;
        $owed = null;

        if (($format === \App\Enums\ReceiptFormat::A4 || $net) && $posBill->customer_name !== null) {
            $customer = Pharmacy::forBusiness($business)->where('name', $posBill->customer_name)->with('account')->first();

            if ($customer?->account !== null) {
                $owed = $balances->directBalance($business, $customer->account->code);
            }
        }

        return view('business.pos.bill', [
            'business' => $business,
            'bill' => $posBill,
            'format' => $format,
            'net' => $net,
            'customer' => $customer,
            'owed' => $owed,
            'customerBills' => $customerBills,
            'canEdit' => $canEdit,
            'blocked' => $blocked,
            'collected' => $outstanding->collectedOn($posBill),
            'owing' => $outstanding->onBill($posBill),
        ]);
    }

    public function update(Request $request, Business $business, PosBill $posBill, EditPosBill $action): RedirectResponse
    {
        $this->authorize('editPosBills', $business);

        $money = ['nullable', 'string', 'regex:/^[\d,]*\.?\d{0,2}$/'];

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.loose' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.unit_price' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'discount' => $money,
            'received' => $money,
            'customer_name' => ['nullable', 'string', 'max:120'],
            'via' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'items.required' => 'A bill has to have something on it. To undo a whole sale, take the goods back as a return.',
            'reason.required' => 'Say why the bill is being changed.',
        ]);

        $clean = fn (?string $v) => Money::of(str_replace([',', ' '], '', (string) $v) ?: '0');

        try {
            $bill = $action->handle(
                $posBill,
                array_values($data['items']),
                $clean($data['discount'] ?? null),
                $clean($data['received'] ?? null),
                $data['customer_name'] ?? null,
                ! empty($data['via'])
                    ? BusinessPaymentMethod::forBusiness($business)->active()->find((int) $data['via'])
                    : null,
                $data['reason'],
                $request->user(),
            );
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        // Corrected at the till: back to the till, ready for the next customer.
        if ($request->input('from') === 'pos') {
            return redirect()
                ->route('businesses.pos.index', $business)
                ->with('status', "Bill {$bill->reference()} corrected. The change is on the bill's history.");
        }

        return redirect()
            ->route('businesses.pos.bills.edit', [$business, $bill])
            ->with('status', "Bill {$bill->reference()} corrected. The change is on its history below.");
    }

    public function returnForm(Business $business, PosBill $posBill, Outstanding $outstanding): View
    {
        $this->authorize('returnAtPos', $business);

        $posBill->load(['lines', 'returns.lines', 'pharmacy']);

        $back = $posBill->returns->flatMap->lines->groupBy('pos_bill_line_id')
            ->map(fn ($lines) => $lines->sum(fn ($l) => $l->pieces()));

        return view('business.pos.return', [
            'business' => $business,
            'bill' => $posBill,
            'back' => $back,
            'owing' => $outstanding->onBill($posBill),
            'dayClosed' => $business->isDayClosed($business->today()),
        ]);
    }

    public function storeReturn(Request $request, Business $business, PosBill $posBill, RecordPosReturn $action): RedirectResponse
    {
        $this->authorize('returnAtPos', $business);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.line_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.loose' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $return = $action->handle($posBill, array_values($data['items']), $data['reason'] ?? null, $request->user());
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        $settled = collect([
            $return->refunded->isPositive() ? 'Rs. '.$return->refunded->format().' to hand back in cash' : null,
            $return->onAccount()->isPositive() ? 'Rs. '.$return->onAccount()->format().' taken off the account' : null,
        ])->filter()->implode(', ');

        return redirect()
            ->route('businesses.pos.bills.edit', [$business, $posBill])
            ->with('status', "Return {$return->reference()} recorded — Rs. {$return->total->format()}: {$settled}. The goods are back in stock.");
    }
}
