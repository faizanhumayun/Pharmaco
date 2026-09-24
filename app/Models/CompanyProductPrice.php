<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Exceptions\ImmutableRecordException;
use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a product cost on a given day.
 *
 * Append-only, for the same reason ledger entries are: "what were we paying for
 * this in March" is a question the answer to which must not depend on what has
 * been imported since.
 */
class CompanyProductPrice extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'company_id', 'company_product_id', 'company_product_import_id',
        'business_date', 'mrp', 'trade_price', 'purchase_rate', 'case_size', 'recorded_by',
        'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'mrp' => MoneyCast::class,
            'trade_price' => MoneyCast::class,
            'purchase_rate' => MoneyCast::class,
            'case_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableRecordException(
            'Price history is append-only. A change in price is recorded as a new row, never as an edit to an old one.'
        ));

        static::deleting(fn () => throw new ImmutableRecordException(
            'Price history is append-only and is never deleted.'
        ));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class, 'company_product_id');
    }

    /** What moved this price, when a price list was not what moved it. */
    public function source(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CompanyProductImport::class, 'company_product_import_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** True when this row records different figures from the one before it. */
    public function differsFrom(?self $previous): bool
    {
        if ($previous === null) {
            return true;
        }

        foreach (['mrp', 'trade_price', 'purchase_rate'] as $field) {
            $mine = $this->{$field};
            $theirs = $previous->{$field};

            if (($mine === null) !== ($theirs === null)) {
                return true;
            }

            if ($mine !== null && ! $mine->equals($theirs)) {
                return true;
            }
        }

        return $this->case_size !== $previous->case_size;
    }
}
