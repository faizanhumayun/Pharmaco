<?php

namespace App\Models;

use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment method this business has switched on.
 *
 * Each one has an account of its own under Bank, so what arrived by JazzCash
 * and what arrived at Meezan are two balances, and neither is in the drawer.
 */
class BusinessPaymentMethod extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'payment_method_id', 'account_id', 'title', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.is_active', true);
    }

    public function label(): string
    {
        return $this->method?->name ?? 'Transfer';
    }
}
