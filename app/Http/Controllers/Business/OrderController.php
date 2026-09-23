<?php

namespace App\Http\Controllers\Business;

use App\Domain\Ledger\BalanceService;
use App\Domain\Orders\Actions\ReceiveOrder;
use App\Domain\Orders\Actions\SaveOrder;
use App\Domain\Orders\Actions\SendOrder;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ReceiveOrderRequest;
use App\Http\Requests\Business\SaveOrderRequest;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\Order;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Order forms: what this business is asking a company to supply.
 *
 * Nothing in here posts. An order is a request, and it stays a request until
 * the goods and the invoice turn up and someone enters the purchase in the
 * daily entry — so no method on this controller goes near the ledger.
 */
class OrderController extends Controller
{
    public function index(Request $request, Business $business): View
    {
        $this->authorize('viewOrders', $business);

        $orders = Order::forBusiness($business)
            ->with(['company', 'creator', 'lines', 'receiptLines'])
            ->when($request->query('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Answers "what have I ordered that has not arrived?" without having
        // to read down the list and work it out.
        $outstanding = Order::forBusiness($business)->outstanding()->with('lines')->get();

        return view('business.orders.index', [
            'business' => $business,
            'orders' => $orders,
            'outstanding' => $outstanding,
            'outstandingTotal' => Money::sum($outstanding->map(fn (Order $o) => $o->total())),
            'companies' => Company::forBusiness($business)->orderBy('name')->get(),
            'filters' => [
                'company' => $request->query('company'),
                'status' => $request->query('status'),
            ],
        ]);
    }

    public function create(Request $request, Business $business): View
    {
        $this->authorize('manageOrders', $business);

        $companies = Company::forBusiness($business)->active()->orderBy('name')->get();
        $company = $request->query('company')
            ? $companies->firstWhere('id', (int) $request->query('company'))
            : null;

        return view('business.orders.edit', [
            'business' => $business,
            'companies' => $companies,
            'company' => $company,
            'order' => null,
            'lines' => [],
        ]);
    }

    public function store(SaveOrderRequest $request, Business $business, SaveOrder $action): RedirectResponse
    {
        $company = $request->company();

        try {
            $order = $action->handle($business, $company, $request->orderData(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.orders.show', [$business, $order])
            ->with('status', "{$order->reference} saved as a draft. Check it, then send it.");
    }

    public function show(Business $business, Order $order): View
    {
        $this->authorize('viewOrders', $business);

        return view('business.orders.show', [
            'business' => $business,
            'order' => $order->loadLines()->load('company', 'creator'),
        ]);
    }

    public function edit(Business $business, Order $order): View
    {
        $this->authorize('manageOrders', $business);

        abort_unless($order->isEditable(), 403, 'A sent order cannot be changed.');

        return view('business.orders.edit', [
            'business' => $business,
            'companies' => Company::forBusiness($business)->active()->orderBy('name')->get(),
            'company' => $order->company,
            'order' => $order->loadLines(),
            'lines' => $order->lines->map(fn ($line) => [
                'company_product_id' => $line->company_product_id,
                'label' => $line->label(),
                'generic_name' => $line->generic_name,
                'pack_size' => $line->pack_size,
                'case_size' => $line->case_size,
                'cartons' => $line->cartons,
                'packs' => $line->packs,
                'rate' => $line->rate->toDecimal(),
                'discount_percent' => $line->discount_percent,
            ])->values()->all(),
        ]);
    }

    public function update(SaveOrderRequest $request, Business $business, Order $order, SaveOrder $action): RedirectResponse
    {
        abort_unless($order->isEditable(), 403);

        try {
            $order = $action->handle($business, $request->company(), $request->orderData(), $request->user(), $order);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.orders.show', [$business, $order])
            ->with('status', "{$order->reference} updated.");
    }

    public function send(Business $business, Order $order, SendOrder $action): RedirectResponse
    {
        $this->authorize('manageOrders', $business);

        try {
            $action->handle($order, request()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', "{$order->reference} marked as sent. It is now fixed as a record of what was asked for.");
    }

    /** The goods-received check: what was ordered, against what turned up. */
    public function receive(Business $business, Order $order, BalanceService $balances): View
    {
        $this->authorize('manageOrders', $business);

        abort_if($order->isEditable(), 403, 'This order has not been sent yet.');

        // A delivery stays correctable until the day its invoice belongs to is
        // closed. Checked here as well as on the button, because a bookmarked
        // URL does not know the day has since been closed.
        abort_if(
            $order->status === OrderStatus::Received && ! $order->deliveryIsEditable(),
            403,
            (string) $order->deliveryLockedReason(),
        );

        $company = $order->company;

        return view('business.orders.receive', [
            'business' => $business,
            // What this supplier is owed on the ledger today, so an overpayment
            // can say whether it is settling earlier bills or running ahead.
            'companyOutstanding' => $company->account
                ? $balances->asAt($business, $company->account->code)
                : Money::zero(),
            // Already received means this is a correction, so the form opens
            // showing what was recorded rather than what was ordered.
            'order' => $order->loadLines()->load('company', 'receiptLines', 'purchaseLine.dailyEntry'),
        ]);
    }

    public function storeReceipt(
        ReceiveOrderRequest $request,
        Business $business,
        Order $order,
        ReceiveOrder $action,
    ): RedirectResponse {
        try {
            $order = $action->handle($order, $request->receiptData(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['status' => $e->getMessage()]);
        }

        $exceptions = $order->deliveryExceptions()->count();

        $message = $exceptions === 0
            ? "{$order->reference} received in full."
            : "{$order->reference} received, with {$exceptions} ".Str::plural('difference', $exceptions).' against the order.';

        if ($line = $order->fresh()->purchaseLine) {
            $message .= sprintf(
                ' The invoice went onto the daily entry for %s — it posts when that day is posted.',
                $line->dailyEntry->business_date->format('j M Y'),
            );
        }

        return redirect()
            ->route('businesses.orders.show', [$business, $order])
            ->with('status', $message);
    }

    /** The form as a PDF, laid out for a page rather than for a screen. */
    public function pdf(Business $business, Order $order): Response
    {
        $this->authorize('viewOrders', $business);

        $pdf = Pdf::loadView('business.orders.pdf', [
            'business' => $business,
            'order' => $order->loadLines()->load('company', 'creator'),
        ])->setPaper('a4');

        return $pdf->download("{$order->reference}-{$order->company->name}.pdf");
    }

    /**
     * The catalogue, as the order builder reads it.
     *
     * Serves both halves of the picker: the search column asks with a term, the
     * browse column asks without one and walks the company's whole list a page
     * at a time. Scoped to one company of this business and always capped, so
     * it cannot be used to pull another tenant's catalogue.
     */
    public function productSearch(Request $request, Business $business): JsonResponse
    {
        $this->authorize('viewOrders', $business);

        $company = Company::forBusiness($business)->find($request->query('company'));

        if ($company === null) {
            return response()->json(['products' => [], 'total' => 0, 'next' => null]);
        }

        $limit = min(max((int) $request->query('limit', 25), 1), 100);
        $offset = max((int) $request->query('offset', 0), 0);

        $query = CompanyProduct::forBusiness($business)
            ->where('company_id', $company->id)
            ->active()
            ->search($request->query('q'));

        $total = (clone $query)->count();

        $products = $query->orderBy('brand_name')->orderBy('strength')
            ->offset($offset)->limit($limit)->get();

        return response()->json([
            'total' => $total,
            // Null once the far end of the list has been reached, which is what
            // the browse column watches to stop asking for more.
            'next' => $offset + $products->count() < $total ? $offset + $products->count() : null,
            'products' => $products->map(fn (CompanyProduct $p) => [
                'id' => $p->id,
                'label' => $p->label(),
                'brand_name' => $p->brand_name,
                'generic_name' => $p->generic_name,
                'strength' => $p->strength,
                'pack_size' => $p->pack_size,
                'case_size' => $p->case_size,
                'rate' => ($p->purchase_rate ?? $p->trade_price)?->toDecimal(),
                'trade_price' => $p->trade_price?->toDecimal(),
                'mrp' => $p->mrp?->toDecimal(),
            ])->values(),
        ]);
    }
}
