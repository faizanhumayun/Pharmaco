<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Exceptions\ImmutableRecordException;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single side of a posting — the source of every balance in the application.
 *
 * Append-only. Nothing in the codebase updates or deletes one of these, and the
 * model refuses to even if something tried.
 */
class LedgerEntry extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id', 'transaction_id', 'account_id', 'business_date',
        'debit', 'credit', 'memo',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'debit' => MoneyCast::class,
            'credit' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::forLedgerEntry());
        static::deleting(fn () => throw ImmutableRecordException::forLedgerEntry());
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->whereHas('transaction', fn ($q) => $q->where('status', '!=', 'draft'));
    }

    /** Signed movement: positive for a debit, negative for a credit. */
    public function signedAmount(): Money
    {
        return $this->debit->minus($this->credit);
    }

    public function isDebit(): bool
    {
        return $this->debit->isPositive();
    }
}
