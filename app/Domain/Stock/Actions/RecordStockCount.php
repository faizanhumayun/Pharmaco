<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Stock\ExpiryWatch;
use App\Domain\Stock\StockLedger;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\StockCount;
use App\Models\StockCountLine;
use Illuminate\Support\Carbon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes down what was on the shelf.
 *
 * Changes no stock. A count is somebody saying what they saw, and saying it is
 * not the same as the books agreeing — a miscount, a pack in the wrong place or
 * a sale rung up moments earlier all produce a difference that should be looked
 * at rather than posted.
 *
 * The expected figure is taken here rather than trusted from the form, so a
 * sheet left open on a screen for an hour cannot record a disagreement with a
 * quantity that has since moved.
 */
class RecordStockCount
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly ExpiryWatch $expiry,
    ) {}

    /**
     * @param  int|null  $counted  null means "whatever the books say" — the
     *                            shelf was checked and agreed, and the figure
     *                            is read here rather than sent from a page that
     *                            may have been open for an hour.
     * @param  list<array{batch_no?: ?string, expires_on?: ?string, packs?: int|string|null, loose?: int|string|null}>|null  $batches
     *         the shelf batch by batch, as a delivery is checked in. The
     *         count's totals are then the sum of these, and $counted and
     *         $countedLoose are ignored.
     */
    public function handle(
        Business $business,
        CompanyProduct $product,
        ?int $counted,
        User $by,
        ?string $note = null,
        int $countedLoose = 0,
        ?array $batches = null,
    ): StockCount {
        if ($product->business_id !== $business->id) {
            throw new RuntimeException('That product belongs to another business.');
        }

        if ($counted !== null && ($counted < 0 || $countedLoose < 0)) {
            throw new RuntimeException('The counted quantity cannot be less than zero.');
        }

        return DB::transaction(function () use ($business, $product, $counted, $countedLoose, $by, $note, $batches) {
            $expected = $this->stock->onHand($business, $product);
            $expectedLoose = $this->stock->looseOnHand($business, $product);
            $per = max(1, (int) $product->units_per_pack);

            $lines = $batches === null ? null : $this->lines($business, $product, $batches, $expected, $expectedLoose, $per);

            if ($lines !== null) {
                $pieces = array_sum(array_map(fn ($l) => $l['counted_packs'] * $per + $l['counted_loose'], $lines));
                $counted = intdiv($pieces, $per);
                $countedLoose = $pieces % $per;
            }

            if ($counted === null) {
                // The shelf agreed: both figures are the books' own.
                $counted = $expected;
                $countedLoose = $expectedLoose;
            } elseif ($lines === null) {
                // Loose pieces that make up whole packs are counted as packs,
                // and a product sold only whole has no loose pieces at all.
                $pieces = $counted * $per + ($per > 1 ? $countedLoose : 0);
                $counted = intdiv($pieces, $per);
                $countedLoose = $pieces % $per;
            }

            $agrees = $counted === $expected && $countedLoose === $expectedLoose;

            $count = StockCount::create([
                'business_id' => $business->id,
                'company_product_id' => $product->id,
                'business_date' => $business->today()->toDateString(),
                'expected_packs' => $expected,
                'expected_loose' => $expectedLoose,
                'counted_packs' => $counted,
                'counted_loose' => $countedLoose,
                // A shelf that matches needs nobody's approval; only a
                // disagreement is a finding.
                'status' => $agrees ? StockCount::AGREED : StockCount::FOUND,
                'note' => $note,
                'counted_by' => $by->id,
                'approved_at' => $agrees ? now() : null,
                'approved_by' => $agrees ? $by->id : null,
            ]);

            foreach ($lines ?? [] as $line) {
                StockCountLine::create($line + [
                    'business_id' => $business->id,
                    'stock_count_id' => $count->id,
                ]);
            }

            return $count;
        });
    }

    /**
     * The shelf's batches set against the books' own.
     *
     * What the books hold of each batch is worked out here, at the moment of
     * writing, as the totals are. Every batch the books know of gets a line —
     * one the counter left out was not found — and stock that came in without
     * a date sits in a line of its own, so the lines always add up to the
     * product's total. A batch on the shelf the books did not know is new.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(Business $business, CompanyProduct $product, array $batches, int $expected, int $expectedLoose, int $per): array
    {
        $key = fn (?string $batch, ?string $date) => mb_strtolower(trim((string) $batch)).'|'.($date ? substr($date, 0, 7) : '');

        $sent = [];
        foreach ($batches as $row) {
            $packs = max(0, (int) ($row['packs'] ?? 0));
            $loose = $per > 1 ? max(0, (int) ($row['loose'] ?? 0)) : 0;
            $date = $this->expiryDate($row['expires_on'] ?? null);
            $batch = trim((string) ($row['batch_no'] ?? '')) ?: null;
            $k = $key($batch, $date);

            // The same batch typed twice is one batch.
            $sent[$k] = [
                'batch_no' => $batch,
                'expires_on' => $date,
                'pieces' => ($sent[$k]['pieces'] ?? 0) + $packs * $per + $loose,
                // The batch's own prices, where the counter gave them.
                'unit_cost' => $this->price($row['unit_cost'] ?? null) ?? ($sent[$k]['unit_cost'] ?? null),
                'mrp' => $this->price($row['mrp'] ?? null) ?? ($sent[$k]['mrp'] ?? null),
            ];
        }

        $held = $this->expiry->heldBatchesByProduct($business, [$product->id])->get($product->id, []);
        $lines = [];
        $dated = 0;
        $rate = ($product->purchase_rate ?? $product->trade_price)?->toDecimal();
        $mrp = $product->mrp?->toDecimal();

        foreach ($held as $batch) {
            $date = $batch['expires_on']->toDateString();
            $k = $key($batch['batch_no'], $date);
            $found = $sent[$k]['pieces'] ?? 0;
            $prices = [
                // As typed, else what this batch came in at, else today's rate.
                'unit_cost' => $sent[$k]['unit_cost'] ?? $batch['unit_cost']?->toDecimal() ?? $rate,
                'mrp' => $sent[$k]['mrp'] ?? $mrp,
            ];
            unset($sent[$k]);
            $dated += $batch['packs'] * $per + $batch['loose'];

            $lines[] = [
                'batch_no' => $batch['batch_no'],
                'expires_on' => $date,
                'expected_packs' => $batch['packs'],
                'expected_loose' => $batch['loose'],
                'counted_packs' => intdiv($found, $per),
                'counted_loose' => $found % $per,
                'is_new' => false,
            ] + $prices;
        }

        // On the books without a date: stock from before dates were kept.
        $undated = max(0, $expected * $per + $expectedLoose - $dated);
        $k = $key(null, null);
        if ($undated > 0 || isset($sent[$k])) {
            $found = $sent[$k]['pieces'] ?? 0;
            $prices = ['unit_cost' => $sent[$k]['unit_cost'] ?? $rate, 'mrp' => $sent[$k]['mrp'] ?? $mrp];
            unset($sent[$k]);
            $lines[] = [
                'batch_no' => null,
                'expires_on' => null,
                'expected_packs' => intdiv($undated, $per),
                'expected_loose' => $undated % $per,
                'counted_packs' => intdiv($found, $per),
                'counted_loose' => $found % $per,
                'is_new' => $undated === 0,
            ] + $prices;
        }

        foreach ($sent as $row) {
            if ($row['pieces'] === 0) {
                continue;
            }

            $lines[] = [
                'batch_no' => $row['batch_no'],
                'expires_on' => $row['expires_on'],
                'expected_packs' => 0,
                'expected_loose' => 0,
                'counted_packs' => intdiv($row['pieces'], $per),
                'counted_loose' => $row['pieces'] % $per,
                'is_new' => true,
                'unit_cost' => $row['unit_cost'] ?? $rate,
                'mrp' => $row['mrp'] ?? $mrp,
            ];
        }

        return $lines;
    }

    /** A price as typed — "1,209.50" — as a plain decimal, or null. */
    private function price(mixed $value): ?string
    {
        $value = str_replace([',', ' '], '', trim((string) $value));

        return $value !== '' && preg_match('/^\d+(\.\d{1,2})?$/', $value) ? $value : null;
    }

    /** "2028-06" as the month's last day, as a delivery reads it. */
    private function expiryDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse(preg_match('/^\d{4}-\d{2}$/', $value) ? $value.'-01' : $value);

            return preg_match('/^\d{4}-\d{2}$/', $value) ? $date->endOfMonth()->toDateString() : $date->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
