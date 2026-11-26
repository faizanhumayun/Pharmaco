<?php

namespace App\Domain\Stock\Audits;

use App\Domain\Stock\ExpiryWatch;
use App\Domain\Stock\StockLedger;
use App\Enums\StockMovementType;
use App\Models\CompanyAudit;
use App\Models\CompanyAuditItem;
use App\Models\CompanyProduct;
use App\Models\PosBillLine;
use App\Models\PosReturnLine;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * A company audit, read as figures.
 *
 * Everything is derived from the audit's counts and the stock movements —
 * nothing is stored as a total. Values are at the prices each product had
 * when it was counted: cost for what the stock is worth, MRP for what the
 * missing goods would have sold for.
 *
 * "Against the till" sets the shortage beside what the till recorded for the
 * same products over the same period (since this company's last audit), which
 * is what turns a shortage from a number into a question: goods that left the
 * rack without a bill.
 */
class CompanyAuditReport
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly ExpiryWatch $expiry,
    ) {}

    /**
     * Every line of the sheet, counted or not.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(CompanyAudit $audit, ?int $onlyItem = null): Collection
    {
        $business = $audit->business;
        $items = $audit->items()->when($onlyItem, fn ($q) => $q->whereKey($onlyItem))
            ->with(['product', 'stockCount.lines', 'stockCount.counter'])->get()
            ->sortBy(fn (CompanyAuditItem $i) => mb_strtolower($i->product->label()))->values();

        $ids = $items->pluck('company_product_id')->all();
        $packs = $this->stock->onHandByProduct($business, null, $ids);
        $loose = $this->stock->looseByProduct($business, null, $ids);
        $batches = $this->expiry->heldBatchesByProduct($business, $ids);
        $sold = $this->soldSince($audit, $ids);

        return $items->map(function (CompanyAuditItem $item) use ($business, $packs, $loose, $batches, $sold) {
            $product = $item->product;
            $per = max(1, (int) $product->units_per_pack);
            $count = $item->stockCount;
            $cost = $item->unit_cost ?? $product->purchase_rate ?? $product->trade_price ?? Money::zero();
            $mrp = $item->mrp ?? $product->mrp ?? Money::zero();

            $expected = $count ? $count->expected_packs * $per + $count->expected_loose : null;
            $counted = $count ? $count->counted_packs * $per + $count->counted_loose : null;
            $diff = $count ? $counted - $expected : null;

            $held = collect($batches->get($product->id, []));
            $nowPieces = (int) ($packs[$product->id] ?? 0) * $per + (int) ($loose[$product->id] ?? 0);
            $undated = max(0, $nowPieces - $held->sum(fn ($b) => $b['packs'] * $per + $b['loose']));

            return [
                'item' => $item,
                'product' => $product,
                'per' => $per,
                'count' => $count,
                'counted' => $count !== null,
                'matched' => $count !== null && $diff === 0,
                // What the records hold now, for a line not yet counted.
                'now_packs' => (int) ($packs[$product->id] ?? 0),
                'now_loose' => (int) ($loose[$product->id] ?? 0),
                'batches' => $held->map(fn ($b) => [
                    'batch_no' => $b['batch_no'],
                    'expires_on' => $b['expires_on']->toDateString(),
                    'month' => $b['expires_on']->format('M Y'),
                    'state' => $this->expiry->stateFor($business, $b['expires_on']),
                    'packs' => $b['packs'],
                    'loose' => $b['loose'],
                    // What this batch came in at.
                    'unit_cost' => $b['unit_cost']?->toDecimal(),
                ])->when($undated > 0, fn ($c) => $c->push([
                    'batch_no' => null, 'expires_on' => null, 'month' => null, 'state' => null,
                    'packs' => intdiv($undated, $per), 'loose' => $undated % $per, 'unit_cost' => null,
                ]))->values()->all(),
                'expected_pieces' => $expected,
                'counted_pieces' => $counted,
                'diff_pieces' => $diff,
                'cost' => $cost,
                'mrp' => $mrp,
                'expected_value' => $expected === null ? null : $this->priced($count, 'unit_cost', $cost, $per, 'expected'),
                'counted_value' => $counted === null ? null : $this->priced($count, 'unit_cost', $cost, $per, 'counted'),
                'diff_value' => $diff === null ? null : $this->priced($count, 'unit_cost', $cost, $per, 'diff'),
                'diff_mrp' => $diff === null ? null : $this->priced($count, 'mrp', $mrp, $per, 'diff'),
                'sold_pieces' => (int) ($sold[$product->id]['pieces'] ?? 0),
                'last_sold' => $sold[$product->id]['last'] ?? null,
            ];
        });
    }

    /** The audit's totals, and the till over the same period. */
    public function summary(CompanyAudit $audit, ?Collection $rows = null): array
    {
        $rows ??= $this->rows($audit);
        $counted = $rows->where('counted', true);
        $short = $counted->filter(fn ($r) => $r['diff_pieces'] < 0);
        $extra = $counted->filter(fn ($r) => $r['diff_pieces'] > 0);

        $records = Money::sum($counted->pluck('expected_value'));
        $found = Money::sum($counted->pluck('counted_value'));
        $change = $found->minus($records);

        $ids = $rows->pluck('product.id')->all();
        $till = $this->till($audit->business, $ids, $audit->period_from?->toDateString(), $audit->business_date->toDateString());

        $shortMrp = Money::sum($short->pluck('diff_mrp'))->absolute();

        return [
            'products' => $rows->count(),
            'counted' => $counted->count(),
            'matched' => $counted->where('matched', true)->count(),
            'short_count' => $short->count(),
            'extra_count' => $extra->count(),
            'records' => $records,
            'found' => $found,
            'change' => $change,
            // As a share of what the records held, to one decimal place.
            'change_percent' => $records->isZero() ? null
                : round((float) $change->toDecimal() / (float) $records->toDecimal() * 100, 1),
            'short' => Money::sum($short->pluck('diff_value')),
            'short_mrp' => $shortMrp->negated(),
            'extra' => Money::sum($extra->pluck('diff_value')),
            'extra_mrp' => Money::sum($extra->pluck('diff_mrp')),
            'net_mrp' => Money::sum($counted->pluck('diff_mrp')),
            'till' => $till,
            // Missing goods at MRP for every 100 the till sold of them.
            'per_hundred' => $till['sold_mrp']->isPositive() && $shortMrp->isPositive()
                ? (int) round((float) $shortMrp->toDecimal() / (float) $till['sold_mrp']->toDecimal() * 100)
                : null,
        ];
    }

    /** Just the change in value, for listing earlier audits. */
    public function change(CompanyAudit $audit): Money
    {
        return $this->summary($audit)['change'];
    }

    /**
     * What was bought and sold of these products over the audit's period.
     *
     * @return array{bought: Money, deliveries: int, sold_mrp: Money, sold_cost: Money, bills: int, from: ?\Illuminate\Support\Carbon}
     */
    public function till(\App\Models\Business $business, array $ids, ?string $from, string $to): array
    {
        $per = CompanyProduct::forBusiness($business)->whereIn('id', $ids)->pluck('units_per_pack', 'id');

        $deliveries = StockMovement::forBusiness($business)
            ->whereIn('company_product_id', $ids)
            ->where('type', StockMovementType::Delivery)
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->whereDate('business_date', '<=', $to)
            ->get(['company_product_id', 'packs', 'loose', 'unit_cost', 'source_type', 'source_id']);

        $bought = Money::sum($deliveries->map(fn ($m) => $this->value(
            $m->unit_cost ?? Money::zero(),
            (int) $m->packs * max(1, (int) ($per[$m->company_product_id] ?? 1)) + (int) $m->loose,
            max(1, (int) ($per[$m->company_product_id] ?? 1)),
        )));

        $inPeriod = fn ($q) => $q->when($from, fn ($d) => $d->whereDate('business_date', '>=', $from))
            ->whereDate('business_date', '<=', $to);

        $lines = PosBillLine::forBusiness($business)->whereIn('company_product_id', $ids)->whereHas('bill', $inPeriod)->get();
        $returns = PosReturnLine::forBusiness($business)->whereIn('company_product_id', $ids)->whereHas('return', $inPeriod)->get();

        return [
            'from' => $from ? \Illuminate\Support\Carbon::parse($from) : null,
            'bought' => $bought,
            'deliveries' => $deliveries->map(fn ($m) => $m->source_type.'#'.$m->source_id)->unique()->count(),
            'sold_mrp' => Money::sum($lines->pluck('line_total'))->minus(Money::sum($returns->pluck('line_total'))),
            'sold_cost' => Money::sum($lines->map(fn (PosBillLine $l) => $l->cost())),
            'bills' => $lines->pluck('pos_bill_id')->unique()->count(),
        ];
    }

    /** @return array<int, array{pieces: int, last: ?\Illuminate\Support\Carbon}> */
    private function soldSince(CompanyAudit $audit, array $ids): array
    {
        $from = $audit->period_from?->toDateString();

        return StockMovement::forBusiness($audit->business)
            ->whereIn('company_product_id', $ids)
            ->whereIn('type', [StockMovementType::Sale, StockMovementType::SaleReturn])
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->with('product:id,units_per_pack')
            ->get(['company_product_id', 'type', 'packs', 'loose', 'business_date'])
            ->groupBy('company_product_id')
            ->map(fn (Collection $ms) => [
                'pieces' => -(int) $ms->sum(fn ($m) => (int) $m->packs * max(1, (int) ($m->product?->units_per_pack ?? 1)) + (int) $m->loose),
                'last' => $ms->where('type', StockMovementType::Sale)->max('business_date'),
            ])->all();
    }

    /**
     * A count's stock at a price, batch by batch: each batch at its own
     * price where the count has one, else the product's. $which is what is
     * valued — what the records held, what was found, or the difference.
     */
    public function priced(?StockCount $count, string $field, Money $fallback, int $per, string $which): Money
    {
        $pieces = fn ($packs, $loose) => $packs * $per + $loose;

        if ($count === null || $count->lines->isEmpty()) {
            $n = match ($which) {
                'expected' => $pieces($count->expected_packs, $count->expected_loose),
                'counted' => $pieces($count->counted_packs, $count->counted_loose),
                default => $count->difference(),
            };

            return $this->value($fallback, $n, $per);
        }

        return Money::sum($count->lines->map(function ($line) use ($field, $fallback, $per, $which, $pieces) {
            $n = match ($which) {
                'expected' => $pieces($line->expected_packs, $line->expected_loose),
                'counted' => $pieces($line->counted_packs, $line->counted_loose),
                default => $line->difference($per),
            };

            return $this->value($line->{$field} ?? $fallback, $n, $per);
        }));
    }

    /** A number of pieces at a price per pack, signed. */
    public function value(Money $perPack, int $pieces, int $per): Money
    {
        $abs = abs($pieces);
        $v = $perPack->times(intdiv($abs, $per))
            ->plus(PosBillLine::piece($perPack, $per)->times($abs % $per));

        return $pieces < 0 ? $v->negated() : $v;
    }
}
