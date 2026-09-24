<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money recovered from one customer on one day. */
class CollectionLine extends Model
{
    protected $fillable = [
        'daily_entry_id', 'pharmacy_id', 'pharmacy_name', 'amount', 'note',
    ];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(DailyEntry::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CollectionAllocation::class);
    }

    /** What this payment did not pin to any particular bill. */
    public function onAccount(): Money
    {
        return $this->amount->minus(Money::sum($this->allocations->pluck('amount')));
    }

    public function label(): string
    {
        return $this->pharmacy?->name ?? $this->pharmacy_name ?? 'Unnamed customer';
    }
}
