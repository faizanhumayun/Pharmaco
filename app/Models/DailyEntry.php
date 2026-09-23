<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DocumentStatus;
use App\Exceptions\ImmutableRecordException;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DailyEntry extends Model
{
    use BelongsToBusiness;

    public const MONEY_FIELDS = [
        'purchase_total', 'purchase_paid', 'purchase_discount', 'purchase_return',
        'sale_cash', 'sale_credit', 'sales_return', 'gross_profit',
        'collection_cash', 'discount_allowed', 'bad_debt',
        'company_payment_cash', 'discount_received',
        'expenses_cash', 'owner_drawing', 'owner_capital',
    ];

    protected $fillable = [
        'business_id', 'business_date', 'status', 'company_note', 'notes', 'created_by',
        ...self::MONEY_FIELDS,
    ];

    protected function casts(): array
    {
        return array_merge(
            [
                'business_date' => 'date',
                'status' => DocumentStatus::class,
                'derived_cogs' => MoneyCast::class,
                'posted_at' => 'datetime',
            ],
            array_fill_keys(self::MONEY_FIELDS, MoneyCast::class),
        );
    }

    protected static function booted(): void
    {
        // A posted day is evidence. Corrections reverse it and re-enter.
        static::updating(function (self $entry) {
            if (DocumentStatus::from($entry->getRawOriginal('status')) === DocumentStatus::Draft) {
                return;
            }

            // Notes stay writable: they are annotation, not figures, and a
            // reversal needs to record on the document that it happened.
            $writable = ['status', 'posted_by', 'posted_at', 'notes', 'updated_at'];
            $disallowed = array_diff(array_keys($entry->getDirty()), $writable);

            if ($disallowed !== []) {
                throw ImmutableRecordException::forDocument('daily entry', $disallowed);
            }
        });

        static::deleting(function (self $entry) {
            if ($entry->status === DocumentStatus::Posted) {
                throw ImmutableRecordException::forDocument('daily entry');
            }
        });
    }

    public function expenseLines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function purchaseLines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Posted);
    }

    // --- Derived figures. Nothing here is stored except derived_cogs. ---

    public function totalPurchases(): Money
    {
        return $this->purchase_total;
    }

    /** What the day's buying actually cost, after any trade discount. */
    public function purchaseNet(): Money
    {
        return $this->purchase_total->minus($this->purchase_discount);
    }

    /**
     * What this day's buying added to what the companies are owed.
     *
     * Zero when the buying was paid for outright — anything paid beyond the
     * cost is not a purchase at all, it is settling an earlier bill.
     */
    public function purchasePending(): Money
    {
        $pending = $this->purchaseNet()->minus($this->purchase_paid);

        return $pending->isNegative() ? Money::zero() : $pending;
    }

    /**
     * Paid beyond what today's buying cost.
     *
     * Comes off the outstanding company payable, which is how a payment against
     * earlier bills is recorded now that it has no field of its own.
     */
    public function purchaseExcess(): Money
    {
        $excess = $this->purchase_paid->minus($this->purchaseNet());

        return $excess->isNegative() ? Money::zero() : $excess;
    }

    public function hasPurchaseDetail(): bool
    {
        return $this->purchaseLines()->exists();
    }

    /** Only net landed cost reaches stock — the trade discount never does. */
    public function costAddedToStock(): Money
    {
        return $this->purchase_total->minus($this->purchase_discount);
    }

    public function totalSales(): Money
    {
        return $this->sale_cash->plus($this->sale_credit);
    }

    public function hasSaleDetail(): bool
    {
        return $this->saleLines()->exists();
    }

    public function hasExpenseDetail(): bool
    {
        return $this->expenseLines()->exists();
    }

    /**
     * Taken from a pharmacy beyond what its invoice came to.
     *
     * Not a sale — it settles credit given on an earlier day, so it moves cash
     * and receivables while leaving the day's sales figure alone. The mirror of
     * purchaseExcess() on the buying side.
     */
    public function saleExcess(): Money
    {
        return Money::sum(
            $this->saleLines->map(fn (SaleLine $line) => $line->received->greaterThan($line->amount)
                ? $line->received->minus($line->amount)
                : Money::zero())
        );
    }

    public function netSales(): Money
    {
        return $this->totalSales()->minus($this->sales_return);
    }

    /** Net sales less the gross profit reported by the POS. */
    public function costOfGoodsSold(): Money
    {
        return $this->netSales()->minus($this->gross_profit);
    }

    public function netProfit(): Money
    {
        return $this->gross_profit->minus($this->expenses_cash)->minus($this->bad_debt);
    }

    public function marginPercent(): ?float
    {
        if ($this->netSales()->isZero()) {
            return null;
        }

        return (float) $this->gross_profit->toDecimal() / (float) $this->netSales()->toDecimal() * 100;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isEmpty(): bool
    {
        foreach (self::MONEY_FIELDS as $field) {
            if (! $this->{$field}->isZero()) {
                return false;
            }
        }

        return true;
    }
}
