<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OpeningBalanceStatus;
use App\Exceptions\ImmutableRecordException;
use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OpeningBalance extends Model
{
    use BelongsToBusiness;

    /** Fields that may still be written once the record is posted. */
    private const POST_FINALIZATION_FIELDS = ['status', 'notes', 'updated_at'];

    protected $fillable = [
        'business_id', 'opening_date', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date',
            'status' => OpeningBalanceStatus::class,
            'total_assets' => MoneyCast::class,
            'total_liabilities' => MoneyCast::class,
            'net_position' => MoneyCast::class,
            'balancing_figure' => MoneyCast::class,
            'finalized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * There is no edit path after finalization and no un-finalize route.
         * Corrections are adjustment transactions dated in the open period.
         *
         * Notes stay writable so an unexplained balancing figure can still be
         * explained after the fact — which is the whole point of surfacing it.
         */
        static::updating(function (self $opening) {
            $original = OpeningBalanceStatus::from($opening->getRawOriginal('status'));

            if ($original->isEditable()) {
                return;
            }

            $disallowed = array_diff(array_keys($opening->getDirty()), self::POST_FINALIZATION_FIELDS);

            if ($disallowed !== []) {
                throw ImmutableRecordException::forDocument('opening balance', $disallowed);
            }
        });

        static::deleting(function (self $opening) {
            if ($opening->status->isPosted()) {
                throw ImmutableRecordException::forDocument('opening balance');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Corrections to the starting position made after it was locked.
     *
     * Each is an adjustment posted in the open period but belonging to the
     * opening date. One that was itself reversed no longer corrects anything.
     */
    public function corrections(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source')
            ->where('type', \App\Enums\TransactionType::Adjustment->value)
            ->whereNull('reversed_by_id')
            ->where('status', '!=', 'draft');
    }

    /**
     * Assets, liabilities and net position once corrections are applied.
     *
     * Each correction moves one account and puts the other side through
     * Opening Balance Equity, so the totals move by the account's side and the
     * net position by the equity side — the two always agree.
     *
     * @return array{assets: \App\Support\Money, liabilities: \App\Support\Money, net: \App\Support\Money}
     */
    public function correctedTotals(): array
    {
        $assets = $this->total_assets;
        $liabilities = $this->total_liabilities;

        foreach ($this->corrections()->with('lines.account')->get() as $correction) {
            foreach ($correction->lines as $line) {
                match ($line->account->type) {
                    \App\Enums\AccountType::Asset => $assets = $assets->plus($line->debit)->minus($line->credit),
                    \App\Enums\AccountType::Liability => $liabilities = $liabilities->plus($line->credit)->minus($line->debit),
                    default => null,
                };
            }
        }

        return ['assets' => $assets, 'liabilities' => $liabilities, 'net' => $assets->minus($liabilities)];
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
