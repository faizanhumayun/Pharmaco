<?php

namespace App\Domain\Stock\Actions;

use App\Enums\StockMovementType;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What the owner decided a difference meant.
 *
 * Approving it moves the stock figure to what was found, through the one class
 * that writes movements — the count does not get its own back door into the
 * quantity ledger. Dismissing it says the shelf was miscounted and leaves the
 * books alone. Either way the count stays on the record: a difference that was
 * judged wrong is still worth having found.
 */
class ResolveStockCount
{
    public function __construct(private readonly RecordStockMovements $movements) {}

    public function approve(StockCount $count, User $by, ?string $reason = null): StockCount
    {
        if (! $count->isOpen()) {
            throw new RuntimeException('That count has already been dealt with.');
        }

        return DB::transaction(function () use ($count, $by, $reason) {
            // In pieces, then said as packs and loose pieces of the same
            // sign — which is how the quantity ledger adds them up.
            $per = max(1, (int) $count->product->units_per_pack);
            $pieces = $count->difference();
            $difference = intdiv($pieces, $per);
            $loose = $pieces % $per;

            // The adjustment carries the count's own words about what happened,
            // so the movement explains itself on the product's page.
            $movement = $this->movements->adjust(
                $count->business,
                $count->product,
                $difference,
                StockMovementType::Adjustment,
                trim(sprintf(
                    'Stock count %s — books %s, shelf %s. %s',
                    $count->business_date->format('j M Y'),
                    $count->expectedLabel(),
                    $count->countedLabel(),
                    $reason ?? $count->note ?? '',
                )),
                $by,
                loose: $loose,
            );

            $count->forceFill([
                'status' => StockCount::ADJUSTED,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'stock_movement_id' => $movement->id,
            ])->save();

            return $count->fresh();
        });
    }

    public function dismiss(StockCount $count, User $by, ?string $reason = null): StockCount
    {
        if (! $count->isOpen()) {
            throw new RuntimeException('That count has already been dealt with.');
        }

        $count->forceFill([
            'status' => StockCount::DISMISSED,
            'approved_by' => $by->id,
            'approved_at' => now(),
            'note' => trim(($count->note ? $count->note.' — ' : '').($reason ?? 'Judged a miscount')),
        ])->save();

        return $count->fresh();
    }
}
