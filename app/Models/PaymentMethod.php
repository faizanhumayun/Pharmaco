<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A way of being paid other than cash: a wallet or a bank.
 *
 * Platform reference data, kept by the back office. It carries no business_id
 * on purpose — the list is the same for everyone, and each business chooses
 * from it through {@see BusinessPaymentMethod}.
 */
class PaymentMethod extends Model
{
    public const WALLET = 'wallet';

    public const BANK = 'bank';

    protected $fillable = ['name', 'kind', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isBank(): bool
    {
        return $this->kind === self::BANK;
    }

    public function kindLabel(): string
    {
        return $this->isBank() ? 'Bank' : 'Mobile wallet';
    }
}
