<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\StockMovementType;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of packs, in or out.
 *
 * The quantity held is the sum of these. Nothing stores a running count, for
 * the same reason nothing stores a running balance: a figure kept in step by
 * maintenance is a figure that can fall out of step.
 */
class StockMovement extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'company_product_id', 'business_date', 'type',
        'packs', 'unit_cost', 'source_type', 'source_id', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'type' => StockMovementType::class,
            'packs' => 'integer',
            'unit_cost' => MoneyCast::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class, 'company_product_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeUpTo(Builder $query, ?string $date): Builder
    {
        return $date === null ? $query : $query->where('business_date', '<=', $date);
    }

    public function isIncoming(): bool
    {
        return $this->packs > 0;
    }

    /** What this movement was worth, when a cost is known for it. */
    public function value(): ?Money
    {
        return $this->unit_cost?->times(abs($this->packs));
    }
}
