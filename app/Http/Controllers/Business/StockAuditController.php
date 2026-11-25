<?php

namespace App\Http\Controllers\Business;

use App\Domain\Stock\Actions\RecordStockCount;
use App\Domain\Stock\Actions\ResolveStockCount;
use App\Domain\Stock\CountSchedule;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\StockCount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Counting the shelves a few lines at a time.
 *
 * A thousand products cannot be counted in a day, so the schedule hands over a
 * sheet each morning and the catalogue comes round continuously. Counting is
 * open to anyone who may verify stock; moving the figure afterwards is not.
 */
class StockAuditController extends Controller
{
    private const SHEET = 15;

    public function index(Request $request, Business $business, CountSchedule $schedule): View
    {
        $this->authorize('verifyStock', $business);

        $ranked = $schedule->ranked($business);
        $today = $business->today();

        $search = trim((string) $request->query('q'));
        $all = $request->query('show') === 'all' || $search !== '';

        $countedToday = StockCount::forBusiness($business)
            ->whereDate('business_date', $today->toDateString())
            ->with(['product', 'counter'])
            ->latest('id')
            ->get();

        // Today's sheet, minus whatever has already been walked.
        $done = $countedToday->pluck('company_product_id')->all();

        return view('business.stock.audit', [
            'business' => $business,
            'today' => $today,
            // Pieces out of opened packs, so "books say" reads the way the
            // stock page does: 139 packs and 1 loose tablet.
            'looseOnHand' => app(\App\Domain\Stock\StockLedger::class)->looseByProduct($business),
            /*
             * Today's sheet by default, or the whole catalogue when asked for —
             * a shopkeeper who has a product in their hand wants to count that
             * one, not wait for it to come round.
             */
            'sheet' => $ranked
                ->when(! $all, fn ($rows) => $rows->filter(
                    fn ($row) => $row['due'] && ! in_array($row['product']->id, $done, true)
                ))
                ->when($search !== '', fn ($rows) => $rows->filter(
                    fn ($row) => str_contains(mb_strtolower($row['product']->label()), mb_strtolower($search))
                        || str_contains(mb_strtolower((string) $row['product']->code), mb_strtolower($search))
                ))
                ->take($all ? 100 : self::SHEET)
                ->values(),
            'all' => $all,
            'search' => $search,
            'countedIds' => $done,
            'countedToday' => $countedToday,
            'open' => StockCount::forBusiness($business)
                ->where('status', StockCount::FOUND)
                ->with(['product', 'counter'])
                ->orderBy('business_date')
                ->get(),
            // How far round the catalogue the shop has got.
            'progress' => [
                'products' => $ranked->count(),
                'due' => $ranked->where('due', true)->count(),
                'never' => $ranked->whereNull('age')->count(),
                'classes' => collect(['A', 'B', 'C'])->mapWithKeys(fn ($c) => [$c => [
                    'total' => $ranked->where('class', $c)->count(),
                    'due' => $ranked->where('class', $c)->where('due', true)->count(),
                    'interval' => $ranked->firstWhere('class', $c)['interval'] ?? null,
                ]]),
            ],
            'canResolve' => $request->user()->can('configure', $business),
        ]);
    }

    public function store(Request $request, Business $business, RecordStockCount $action): RedirectResponse
    {
        $this->authorize('verifyStock', $business);

        $data = $request->validate([
            'company_product_id' => ['required', 'integer'],
            // Absent when the shelf simply agreed: the figure is then read from
            // the books at the moment of writing rather than posted.
            'counted_packs' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // Pieces in the open pack, for products whose packs are opened.
            'counted_loose' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'agrees' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $request->boolean('agrees') && $data['counted_packs'] === null) {
            return back()->withErrors(['counted_packs' => 'Enter the counted quantity, or confirm that the count matches.']);
        }

        $product = CompanyProduct::forBusiness($business)->findOrFail($data['company_product_id']);

        $count = $action->handle(
            $business,
            $product,
            $request->boolean('agrees') ? null : (int) $data['counted_packs'],
            $request->user(),
            $data['note'] ?? null,
            (int) ($data['counted_loose'] ?? 0),
        );

        return back()->with('status', $count->difference() === 0
            ? sprintf('%s counted. The count matches the system.', $product->label())
            : sprintf(
                '%s counted %s compared with the system. It is waiting for approval.',
                $product->label(),
                $count->differenceLabel(),
            ));
    }

    /** Approving a difference is the owner's call, not the counter's. */
    public function resolve(Request $request, Business $business, StockCount $stockCount, ResolveStockCount $action): RedirectResponse
    {
        $this->authorize('configure', $business);

        abort_if($stockCount->business_id !== $business->id, 403);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,dismiss'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $data['decision'] === 'approve'
                ? $action->approve($stockCount, $request->user(), $data['reason'] ?? null)
                : $action->dismiss($stockCount, $request->user(), $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('status', $data['decision'] === 'approve'
            ? 'Stock corrected to the counted quantity.'
            : 'Stock was not changed. The count was marked as a counting mistake.');
    }
}
