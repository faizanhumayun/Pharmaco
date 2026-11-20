<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One correction to a bill: how it stood before, how it stood after, and why.
 *
 * Written once and never changed. The history of a bill is only worth having
 * if it cannot itself be edited.
 */
class PosBillRevision extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'pos_bill_id', 'revision_no', 'reason', 'before', 'after', 'changes', 'edited_by',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'changes' => 'array', 'revision_no' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::forDocument('bill revision'));
        static::deleting(fn () => throw ImmutableRecordException::forDocument('bill revision'));
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PosBill::class, 'pos_bill_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
