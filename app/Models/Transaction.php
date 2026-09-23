<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\ImmutableRecordException;
use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    use BelongsToBusiness;

    /**
     * The only fields a posted transaction may still have written to it.
     *
     * Reversing links the original to its mirror in both directions, which is a
     * change to the record after posting. Whitelisting exactly those two fields
     * keeps that possible without opening a general edit path.
     */
    private const REVERSAL_LINK_FIELDS = ['reversed_by_id', 'status', 'updated_at'];

    protected $fillable = [
        'business_id', 'business_date', 'type', 'reference_no', 'narration',
        'amount', 'source_type', 'source_id', 'status', 'reversal_of_id',
        'reversed_by_id', 'correction_reason', 'original_business_date',
        'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'original_business_date' => 'date',
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => MoneyCast::class,
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Immutability is enforced here rather than in a controller because a
         * controller is one of many callers, and this is the guarantee the whole
         * audit trail rests on. A posted transaction cannot be edited by any
         * role, including the App Owner.
         */
        static::updating(function (self $transaction) {
            // getRawOriginal, not getOriginal: the latter returns the cast enum.
            $original = TransactionStatus::from($transaction->getRawOriginal('status'));

            if (! $original->isImmutable()) {
                return;
            }

            $changed = array_keys($transaction->getDirty());
            $disallowed = array_diff($changed, self::REVERSAL_LINK_FIELDS);

            if ($disallowed !== []) {
                throw ImmutableRecordException::forTransaction(
                    'has been posted (attempted to change: ' . implode(', ', $disallowed) . ')'
                );
            }
        });

        static::deleting(function (self $transaction) {
            if ($transaction->status->isImmutable()) {
                throw ImmutableRecordException::forTransaction('has been posted and cannot be deleted');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The document that caused this posting — a daily entry, an opening balance. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', '!=', TransactionStatus::Draft);
    }

    public function scopeOfType(Builder $query, TransactionType ...$types): Builder
    {
        return $query->whereIn('type', array_column($types, 'value'));
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('business_date', $date);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('business_date', [$from, $to]);
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /** True when the document was posted to a later day than it belongs to. */
    public function wasPostedLate(): bool
    {
        return $this->original_business_date !== null
            && ! $this->original_business_date->equalTo($this->business_date);
    }
}
