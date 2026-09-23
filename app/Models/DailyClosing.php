<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClosing extends Model
{
    use BelongsToBusiness;

    public const FIGURES = [
        'opening_stock', 'opening_cash', 'opening_receivable', 'opening_payable',
        'purchases', 'purchases_paid', 'purchases_on_account',
        'sales', 'cogs', 'gross_profit', 'expenses', 'net_profit',
        'collections', 'company_payments', 'credit_sales', 'cash_sales',
        'closing_stock', 'closing_cash', 'closing_receivable', 'closing_payable',
        'receivable_delta', 'payable_delta', 'net_position',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_merge(
            [
                'business_date' => 'date',
                'counted_cash' => MoneyCast::class,
                'cash_variance' => MoneyCast::class,
                'finalized_at' => 'datetime',
                'built_at' => 'datetime',
            ],
            array_fill_keys(self::FIGURES, MoneyCast::class),
        );
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function scopeFinalized(Builder $query): Builder
    {
        return $query->where('status', 'finalized');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    public function hasCashVariance(): bool
    {
        return $this->cash_variance !== null && ! $this->cash_variance->isZero();
    }

    /** @return array<string, Money> */
    public function figures(): array
    {
        return collect(self::FIGURES)->mapWithKeys(fn ($f) => [$f => $this->{$f}])->all();
    }
}
