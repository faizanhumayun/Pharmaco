<?php

namespace App\Models;

use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one product's shelf held on one day, against what the books said.
 *
 * A count is evidence, not a correction: it records a disagreement. Putting
 * stock right is a separate, deliberate act.
 */
class StockCount extends Model
{
    use BelongsToBusiness;

    public const FOUND = 'found';        // counted, difference not yet dealt with
    public const AGREED = 'agreed';      // shelf and books matched
    public const ADJUSTED = 'adjusted';  // difference approved and stock moved
    public const DISMISSED = 'dismissed'; // difference judged a miscount

    protected $fillable = [
        'business_id', 'company_product_id', 'business_date',
        'expected_packs', 'expected_loose', 'counted_packs', 'counted_loose', 'status', 'note',
        'counted_by', 'approved_by', 'approved_at', 'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'expected_packs' => 'integer',
            'counted_packs' => 'integer',
            'expected_loose' => 'integer',
            'counted_loose' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class, 'company_product_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Found on the shelf less what the books held, in pieces — whole packs
     * and the open pack's pieces together. Negative means short. For a
     * product sold only by the pack, a piece is a pack.
     */
    public function difference(): int
    {
        $per = max(1, (int) ($this->product?->units_per_pack ?? 1));

        return ($this->counted_packs * $per + $this->counted_loose)
            - ($this->expected_packs * $per + $this->expected_loose);
    }

    /** The difference as it is said: "+2 packs", "−1 pack and 3 loose tablets". */
    public function differenceLabel(): string
    {
        $per = max(1, (int) ($this->product?->units_per_pack ?? 1));
        $pieces = abs($this->difference());

        return ($this->difference() < 0 ? '−' : '+')
            . $this->product->stockLabel(intdiv($pieces, $per), $pieces % $per);
    }

    /** What the books held, and what was found, as the stock page says them. */
    public function expectedLabel(): string
    {
        return $this->product->stockLabel($this->expected_packs, $this->expected_loose);
    }

    public function countedLabel(): string
    {
        return $this->product->stockLabel($this->counted_packs, $this->counted_loose);
    }

    /** Still waiting for somebody to decide what the difference means. */
    public function isOpen(): bool
    {
        return $this->status === self::FOUND;
    }
}
