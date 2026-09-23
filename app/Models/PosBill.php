<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale over the counter.
 *
 * The financial record is still the day's entry: a bill puts one sale line on
 * it, carrying the bill's total and what was taken in cash. This holds the
 * detail behind that line, so the day's gross profit is the sum of real
 * margins rather than a figure typed from memory.
 */
class PosBill extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'business_date', 'daily_entry_id', 'bill_no', 'customer_name',
        'total', 'discount', 'received', 'cost', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'bill_no' => 'integer',
            'total' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'received' => MoneyCast::class,
            'cost' => MoneyCast::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosBillLine::class);
    }

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(DailyEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What the counter is still owed on this bill. */
    public function credit(): Money
    {
        $owed = $this->total->minus($this->received);

        return $owed->isNegative() ? Money::zero() : $owed;
    }

    /** Change handed back: anything taken beyond the bill. */
    public function change(): Money
    {
        $over = $this->received->minus($this->total);

        return $over->isNegative() ? Money::zero() : $over;
    }

    /** Sale value less what the goods cost. */
    public function margin(): Money
    {
        return $this->total->minus($this->cost);
    }

    public function reference(): string
    {
        return 'B-' . str_pad((string) $this->bill_no, 5, '0', STR_PAD_LEFT);
    }
}
