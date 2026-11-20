<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods brought back against a bill.
 *
 * The bill itself is left exactly as it was sold. This is the correction: what
 * came back, what it had been sold for, and whether the customer was handed
 * cash or had it taken off what they owe.
 */
class PosReturn extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'pos_bill_id', 'pharmacy_id', 'daily_entry_id', 'business_date',
        'return_no', 'total', 'cost', 'refunded', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'return_no' => 'integer',
            'total' => MoneyCast::class,
            'cost' => MoneyCast::class,
            'refunded' => MoneyCast::class,
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PosBill::class, 'pos_bill_id');
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosReturnLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): string
    {
        return 'R-'.str_pad((string) $this->return_no, 5, '0', STR_PAD_LEFT);
    }

    /** Taken off the customer's account rather than handed back. */
    public function onAccount(): Money
    {
        return $this->total->minus($this->refunded);
    }

    /** The profit the original sale had booked on these goods. */
    public function margin(): Money
    {
        return $this->total->minus($this->cost);
    }
}
