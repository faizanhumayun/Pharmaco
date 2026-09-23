<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    protected $fillable = [
        'daily_entry_id', 'pharmacy_id', 'pharmacy_name', 'invoice_no', 'amount', 'received',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'received' => MoneyCast::class,
        ];
    }

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(DailyEntry::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    /** What this pharmacy still owes on this invoice. */
    public function outstanding(): Money
    {
        return $this->amount->minus($this->received);
    }

    public function label(): string
    {
        return trim(($this->pharmacy?->name ?? $this->pharmacy_name ?? 'Unnamed pharmacy')
            . ($this->invoice_no ? " · {$this->invoice_no}" : ''));
    }
}
