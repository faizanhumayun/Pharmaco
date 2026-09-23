<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseLine extends Model
{
    protected $fillable = ['daily_entry_id', 'expense_category_id', 'amount', 'description'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(DailyEntry::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function label(): string
    {
        return trim(($this->category?->name ?? 'Uncategorised')
            . ($this->description ? " · {$this->description}" : ''));
    }
}
