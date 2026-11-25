<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The day's side of a return: whose it was, and how it was settled.
 *
 * Without it a return could only come off the unallocated receivable, and a
 * cash refund would leave the drawer short with nothing in the books to say why.
 */
class ReturnLine extends Model
{
    protected $fillable = [
        'daily_entry_id', 'pharmacy_id', 'pharmacy_name', 'reference', 'amount', 'refunded',
    ];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'refunded' => MoneyCast::class];
    }

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(DailyEntry::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    /** Taken off the account rather than refunded. */
    public function onAccount(): Money
    {
        $rest = $this->amount->minus($this->refunded);

        return $rest->isNegative() ? Money::zero() : $rest;
    }

    public function label(): string
    {
        return trim(($this->pharmacy?->name ?? $this->pharmacy_name ?? 'Walk-in customer')
            .($this->reference ? " · {$this->reference}" : ''));
    }
}
