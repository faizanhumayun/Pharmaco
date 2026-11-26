<?php

namespace App\Domain\Stock\Audits\Actions;

use App\Domain\Products\Actions\UpdateProduct;
use App\Domain\Stock\Actions\RecordStockCount;
use App\Models\CompanyProduct;
use App\Support\PackSize;
use App\Models\CompanyAudit;
use App\Models\CompanyAuditItem;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One product of a company audit, counted.
 *
 * Written by the same action as any stock count, so the figure the records
 * held is read at this moment — a sale rung up while the audit is under way
 * is not counted as missing. Counting a product again replaces its count in
 * the audit; the one replaced stays on file, marked as such.
 */
class CountAuditItem
{
    public function __construct(
        private readonly RecordStockCount $counts,
        private readonly UpdateProduct $update,
    ) {}

    /**
     * @param  array{agrees?: bool, packs?: int|string|null, loose?: ?int, batches?: ?array, note?: ?string, product?: array<string, string>}  $data
     *         product: pack size, purchase rate and MRP as found on the carton,
     *         put on the product before the count is valued — the caller has
     *         checked the person may edit products.
     */
    public function handle(CompanyAudit $audit, CompanyAuditItem $item, array $data, User $by): CompanyAuditItem
    {
        if (! $audit->isCounting()) {
            throw new RuntimeException('This audit has been finished. Send it back for a recount to change a figure.');
        }

        if ($item->company_audit_id !== $audit->id) {
            throw new RuntimeException('That product is not on this audit.');
        }

        return DB::transaction(function () use ($audit, $item, $data, $by) {
            // Read afresh: the count it holds may have changed since it was loaded.
            $item->refresh();

            // A batch found on the rack that the records did not have is the
            // newest stock: its prices are the product's from now on. A batch
            // already on the books keeps the rate it came in at.
            $fields = $data['product'] ?? [];
            foreach (array_reverse($data['batches'] ?? []) as $line) {
                if (! empty($line['is_new']) && (! empty($line['unit_cost']) || ! empty($line['mrp']))) {
                    $fields += array_filter(['purchase_rate' => $line['unit_cost'] ?? null, 'mrp' => $line['mrp'] ?? null]);
                    break;
                }
            }

            $product = $this->correctProduct($item->product, $fields, $by);
            $previous = $item->stockCount;

            if ($previous && $previous->isOpen()) {
                $previous->forceFill([
                    'status' => StockCount::DISMISSED,
                    'approved_by' => $by->id,
                    'approved_at' => now(),
                    'note' => trim(($previous->note ? $previous->note.' — ' : '').'Replaced by a recount in the company audit'),
                ])->save();
            }

            $agrees = (bool) ($data['agrees'] ?? false);
            $per = max(1, (int) $product->units_per_pack);

            // Part of a pack, said as packs and pieces: 0.7 of 28 is 20 tablets.
            $batches = $agrees || ! isset($data['batches']) ? null : array_map(function ($line) use ($product, $per) {
                [$packs, $loose] = $this->packsAndPieces($product, $per, $line['packs'] ?? 0, $line['loose'] ?? 0);

                return ['packs' => $packs, 'loose' => $loose] + $line;
            }, $data['batches']);

            [$packs, $loose] = $agrees || $batches !== null ? [null, 0]
                : $this->packsAndPieces($product, $per, $data['packs'] ?? 0, $data['loose'] ?? 0);

            /*
             * No pack size ("Nos", or the 0 the screen shows for it) means
             * nobody knows what a pack of this product holds, so a count of
             * its packs says nothing. It is entered first. Finding nothing on
             * the rack needs no pack size, so that is let through.
             */
            $nothing = ! $agrees && ($batches !== null
                ? collect($batches)->every(fn ($l) => (int) $l['packs'] === 0 && (int) $l['loose'] === 0)
                : $packs === 0 && $loose === 0);

            if (! $nothing && preg_match('/^(0|nos\.?)?$/i', trim((string) $product->pack_size))) {
                throw new RuntimeException("Enter the pack size of {$product->label()} before counting it. A pack size of 0 cannot be saved.");
            }

            $count = $this->counts->handle(
                $audit->business,
                $product,
                $packs,
                $by,
                $data['note'] ?? null,
                $loose,
                $batches,
            );

            // Valued at the prices of the moment it was counted, so the report
            // does not move when a price list is applied next week.
            $item->forceFill([
                'stock_count_id' => $count->id,
                'unit_cost' => $product->purchase_rate?->toDecimal() ?? $product->trade_price?->toDecimal(),
                'mrp' => $product->mrp?->toDecimal(),
            ])->save();

            return $item->fresh(['stockCount.lines', 'product']);
        });
    }

    /**
     * Name, strength, type, pack size, purchase rate and MRP as found on the carton, onto the
     * product — through the one action that changes products, so the price
     * history and the activity log have them, and a pack size that would
     * reread pieces already sold is refused.
     */
    private function correctProduct(CompanyProduct $product, array $fields, User $by): CompanyProduct
    {
        $changes = [];

        // Name, strength and type, as on the pack.
        foreach (['brand_name', 'strength', 'dosage_form'] as $field) {
            if (array_key_exists($field, $fields) && trim((string) $fields[$field]) !== (string) $product->{$field}) {
                $changes[$field] = trim((string) $fields[$field]);
            }
        }

        if (array_key_exists('brand_name', $changes) && $changes['brand_name'] === '') {
            throw new RuntimeException('Enter the product name.');
        }

        if (isset($fields['pack_size']) && trim($fields['pack_size']) !== (string) $product->pack_size) {
            $changes['pack_size'] = trim($fields['pack_size']);
            $pieces = PackSize::pieces($changes['pack_size']);

            if ($product->business->sellsLoose() && $pieces > 1 && $pieces !== (int) $product->units_per_pack) {
                $changes['units_per_pack'] = $pieces;
            }
        }

        foreach (['purchase_rate', 'mrp'] as $field) {
            if (isset($fields[$field]) && $fields[$field] !== $product->{$field}?->toDecimal()) {
                $changes[$field] = $fields[$field];
            }
        }

        return $changes === [] ? $product : $this->update->handle($product, $changes, $by);
    }

    /**
     * A quantity typed in packs — whole, or part of one — plus any loose
     * pieces, as whole packs and pieces. Part of a pack only where packs are
     * opened; rounded to the nearest whole piece.
     *
     * @return array{0: int, 1: int}
     */
    private function packsAndPieces(CompanyProduct $product, int $per, int|string|float $packs, int|string|null $loose): array
    {
        $packs = (string) $packs === '' ? '0' : (string) $packs;
        $whole = is_numeric($packs) && (float) $packs === floor((float) $packs);

        if (! $whole && $per < 2) {
            throw new RuntimeException("{$product->label()} is not sold loose, so count it in whole packs. Set its pack size first if its packs are opened.");
        }

        $pieces = $whole
            ? (int) (float) $packs * $per
            : (int) round((float) $packs * $per);
        $pieces += $per > 1 ? max(0, (int) $loose) : 0;

        return [intdiv($pieces, $per), $pieces % $per];
    }
}
