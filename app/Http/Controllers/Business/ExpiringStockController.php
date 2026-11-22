<?php

namespace App\Http\Controllers\Business;

use App\Domain\Stock\ExpiryWatch;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A sweep of the shelves, sorted by how long is left.
 *
 * The counter colours short-dated goods as they come up, which catches them one
 * sale at a time. This is the other half: everything at once, so a shelf can be
 * walked with a list in hand.
 *
 * Two tabs, because they are two different jobs. Near expiry is stock that can
 * still be sold or sent back in time. Expired is stock that has not gone back
 * to the company — every pack of it is money lost at cost until it does.
 */
class ExpiringStockController extends Controller
{
    public function index(
        Request $request,
        Business $business,
        ExpiryWatch $watch,
    ): View {
        $this->authorize('viewProducts', $business);

        $today = $business->today();
        $held = $watch->heldBatchesByProduct($business);

        /*
         * Everything is shown by default, not only what is already a problem —
         * a buyer wants to see what is coming as well as what has arrived. The
         * window narrows it to the business's own definition of soon.
         */
        $within = $request->integer('within') ?: null;
        $horizon = $within !== null
            ? $today->copy()->addMonths($within)->endOfDay()
            : $business->expiryHorizon();

        $products = CompanyProduct::forBusiness($business)
            ->whereIn('id', $held->keys())
            ->with('company:id,name')
            ->get()
            ->keyBy('id');

        // One row per batch, valued at the packs in that batch alone: a product
        // with an expired carton and a fresh one is not all a loss.
        $rows = $held
            ->flatMap(function (array $batches, int $id) use ($products, $today, $watch) {
                $product = $products->get($id);

                if ($product === null) {
                    return [];
                }

                return array_map(fn (array $batch) => [
                    'product' => $product,
                    'expires_on' => $batch['expires_on'],
                    // Whole days, signed: negative once the date has passed.
                    'days' => (int) $today->copy()->startOfDay()->diffInDays($batch['expires_on'], false),
                    'packs' => $batch['packs'],
                    // Pieces out of an opened pack that belong to this batch.
                    'loose' => $batch['loose'],
                    'batch_no' => $batch['batch_no'] ?? null,
                    'value' => $watch->valueAtCost($product, $batch),
                ], $batches);
            })
            ->sortBy(fn (array $row) => $row['expires_on']->timestamp)
            ->values();

        // Expired is never narrowed by the window: a loss is a loss however old.
        $expired = $rows->filter(fn (array $row) => $row['days'] < 0)->values();
        $near = $rows
            ->filter(fn (array $row) => $row['days'] >= 0)
            ->when($horizon !== null, fn ($rows) => $rows->filter(
                fn (array $row) => $row['expires_on']->lte($horizon)
            ))
            ->values();

        $show = $request->query('show') === 'expired' ? 'expired' : 'near';

        return view('business.stock.expiring', [
            'business' => $business,
            'show' => $show,
            'rows' => $show === 'expired' ? $expired : $near,
            'today' => $today,
            'within' => $within ?? $business->expiryAlertMonths(),
            'near' => $near,
            'expired' => $expired,
            'nearValue' => Money::sum($near->pluck('value')),
            'loss' => Money::sum($expired->pluck('value')),
            // Returns go back company by company, so the loss is owed back that way.
            'lossByCompany' => $expired
                ->groupBy(fn (array $row) => $row['product']->company?->name ?? '—')
                ->map(fn ($rows) => [
                    'packs' => $rows->sum('packs'),
                    'value' => Money::sum($rows->pluck('value')),
                ])
                ->sort(fn (array $a, array $b) => $b['value']->compareTo($a['value'])),
        ]);
    }
}
