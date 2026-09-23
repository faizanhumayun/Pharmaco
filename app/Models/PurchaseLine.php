<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseLine extends Model
{
    protected $fillable = [
        'daily_entry_id', 'company_id', 'company_name', 'order_id', 'invoice_no', 'amount', 'paid',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'paid' => MoneyCast::class,
        ];
    }

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(DailyEntry::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The order form this invoice answers, when it came from a delivery. */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** What is still owed on this invoice, before any trade discount. */
    public function pending(): Money
    {
        return $this->amount->minus($this->paid);
    }

    public function label(): string
    {
        return trim(($this->company?->name ?? $this->company_name ?? 'Unnamed company')
            .($this->invoice_no ? " · {$this->invoice_no}" : ''));
    }
}
