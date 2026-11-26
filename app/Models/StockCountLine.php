<?php

namespace App\Models;

use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One batch of a count: what the books held of it, and what was found. */
class StockCountLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'stock_count_id', 'batch_no', 'expires_on',
        'expected_packs', 'expected_loose', 'counted_packs', 'counted_loose', 'is_new',
        'unit_cost', 'mrp',
    ];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'expected_packs' => 'integer',
            'expected_loose' => 'integer',
            'counted_packs' => 'integer',
            'counted_loose' => 'integer',
            'is_new' => 'boolean',
            'unit_cost' => \App\Casts\MoneyCast::class,
            'mrp' => \App\Casts\MoneyCast::class,
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    /** Found less held, in pieces. */
    public function difference(int $perPack): int
    {
        $per = max(1, $perPack);

        return ($this->counted_packs * $per + $this->counted_loose)
            - ($this->expected_packs * $per + $this->expected_loose);
    }
}
