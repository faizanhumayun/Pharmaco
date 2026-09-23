<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Exceptions\ImmutableRecordException;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * An order form: what this business is asking a company to supply.
 *
 * It is a document, not a transaction. Nothing about it reaches the ledger,
 * because nothing has been bought — no stock has moved and nothing is owed
 * until the goods and the invoice arrive, and that is entered in the daily
 * entry like any other purchase. Keeping the two apart is deliberate: an order
 * that is never fulfilled must leave no trace in the figures.
 *
 * Its total is derived from its lines and never stored.
 */
class Order extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'company_id', 'reference', 'business_date',
        'status', 'discount_percent', 'notes', 'created_by', 'sent_at',
        'received_at', 'received_by',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => OrderStatus::class,
            'discount_percent' => 'decimal:3',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Once it has gone to the company it is a record of what was asked for.
        // Changing it afterwards would make the copy they hold and the copy we
        // hold disagree, which is the whole reason for having a reference.
        //
        // The single exception is being marked received: that records what
        // arrived without touching a word of what was ordered, so it is allowed
        // through — and only it, and only from sent.
        static::updating(function (self $order) {
            // getRawOriginal, not getOriginal: the latter puts the value back
            // through the cast and hands back an enum, so comparing it to a
            // string is quietly false for every status and the guard never
            // fires at all.
            $original = $order->getRawOriginal('status');

            if ($original === OrderStatus::Draft->value) {
                return;
            }

            // Recording the delivery, or correcting what was recorded. Both
            // touch only the receiving fields; the order itself never moves.
            $receiving = in_array($original, [OrderStatus::Sent->value, OrderStatus::Received->value], true)
                && $order->status === OrderStatus::Received
                && array_diff(array_keys($order->getDirty()), ['status', 'received_at', 'received_by', 'updated_at']) === [];

            if (! $receiving) {
                throw new ImmutableRecordException(
                    $original === OrderStatus::Received->value
                        ? 'This order has been received. The delivery can be corrected, but the order itself cannot.'
                        : 'This order has been sent and cannot be changed. Write a new one.'
                );
            }
        });

        static::deleting(fn () => throw new ImmutableRecordException(
            'Order forms are not deleted. A draft you no longer want can simply be left.'
        ));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position')->orderBy('id');
    }

    /** What actually turned up, once it has. */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(OrderReceiptLine::class)->orderBy('position')->orderBy('id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Draft);
    }

    /** Sent, and still waiting on a delivery. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Sent);
    }

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }

    /** Whole days since it went to the company. */
    public function daysWaiting(): ?int
    {
        return $this->sent_at?->diffInDays(now());
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Load the lines with the order attached to each, so a line can see the
     * discount the order committed to without going back to the database.
     */
    public function loadLines(): self
    {
        $this->load('lines');
        $this->lines->each(fn (OrderLine $line) => $line->setRelation('order', $this));

        return $this;
    }

    /** The order before any discount. */
    public function gross(): Money
    {
        return Money::sum($this->lines->map(fn (OrderLine $line) => $line->gross()));
    }

    /**
     * Discount is worked out line by line and then added up, never taken off
     * the order total in one go. The two differ by a paisa or two once rounding
     * is involved, and the line figures are the ones on the form the company
     * will check against — so those are the ones that must add up.
     */
    public function discountTotal(): Money
    {
        return Money::sum($this->lines->map(
            fn (OrderLine $line) => $line->discountAmount($this->discount_percent)
        ));
    }

    public function total(): Money
    {
        return $this->gross()->minus($this->discountTotal());
    }

    /**
     * Ordered against received, line by line.
     *
     * Derived every time rather than stored: the two documents each say what
     * they say, and the difference between them is an observation about the
     * pair, not a third fact that could fall out of step with either.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function delivery(): Collection
    {
        $received = $this->receiptLines->keyBy('order_line_id');

        $rows = $this->lines->map(function (OrderLine $line) use ($received) {
            $receipt = $received->get($line->id);
            $got = $receipt?->packs ?? 0;

            return [
                'line' => $line,
                'receipt' => $receipt,
                'ordered' => $line->packs,
                'received' => $got,
                'difference' => $got - $line->packs,
            ];
        });

        // Anything sent that was never asked for, listed after the rest.
        $extras = $this->receiptLines
            ->filter(fn (OrderReceiptLine $r) => $r->isUnordered())
            ->map(fn (OrderReceiptLine $r) => [
                'line' => null,
                'receipt' => $r,
                'ordered' => 0,
                'received' => $r->packs,
                'difference' => $r->packs,
            ]);

        return $rows->concat($extras)->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function deliveryExceptions(): Collection
    {
        return $this->delivery()->filter(fn (array $row) => $row['difference'] !== 0)->values();
    }

    public function isFullyDelivered(): bool
    {
        return $this->status === OrderStatus::Received && $this->deliveryExceptions()->isEmpty();
    }

    /** The invoice raised from this delivery, once one has been entered. */
    public function purchaseLine(): HasOne
    {
        return $this->hasOne(PurchaseLine::class);
    }

    /**
     * Whether the recorded delivery can still be corrected.
     *
     * A delivery with no invoice on it is only a record of goods, so nothing
     * stops it being fixed. Once an invoice has joined a day's entry, it can be
     * corrected until that day is closed — after a close the figures have been
     * agreed and reported, and the only route left is an adjustment.
     */
    public function deliveryIsEditable(): bool
    {
        if ($this->status !== OrderStatus::Received) {
            return false;
        }

        $line = $this->purchaseLine;

        if ($line?->dailyEntry === null) {
            return true;
        }

        return ! $this->business->isDayClosed($line->dailyEntry->business_date);
    }

    /** Why it cannot, for saying so on screen. */
    public function deliveryLockedReason(): ?string
    {
        if ($this->deliveryIsEditable() || $this->status !== OrderStatus::Received) {
            return null;
        }

        return sprintf(
            'The day this invoice belongs to (%s) has been closed, so the delivery can no longer be changed.',
            $this->purchaseLine->dailyEntry->business_date->format('j M Y'),
        );
    }

    /** What the delivery comes to, as against what the order came to. */
    public function receivedTotal(): Money
    {
        return Money::sum($this->receiptLines->map(
            fn (OrderReceiptLine $line) => $line->amount($this->discount_percent)
        ));
    }

    public function hasDiscount(): bool
    {
        return ! $this->discountTotal()->isZero();
    }

    /** True when some line was given a rate of its own. */
    public function hasLineDiscounts(): bool
    {
        return $this->lines->contains(fn (OrderLine $line) => $line->hasOwnDiscount());
    }

    public function totalPacks(): int
    {
        return (int) $this->lines->sum('packs');
    }

    /** Cartons only count where a carton size was known for the product. */
    public function totalCartons(): int
    {
        return (int) $this->lines->sum(fn (OrderLine $line) => $line->cartons ?? 0);
    }

    /**
     * The next reference for this business, as ORD-0001.
     *
     * Read inside the same transaction that writes the order, with the unique
     * index on (business_id, reference) as the backstop if two are written at
     * the same moment.
     */
    public static function nextReference(Business $business): string
    {
        $last = static::forBusiness($business)
            ->where('reference', 'like', 'ORD-%')
            ->orderByDesc('id')
            ->value('reference');

        $number = $last === null ? 0 : (int) substr($last, 4);

        return 'ORD-'.str_pad((string) ($number + 1), 4, '0', STR_PAD_LEFT);
    }
}
