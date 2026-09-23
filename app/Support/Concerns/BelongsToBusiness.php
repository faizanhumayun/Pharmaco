<?php

namespace App\Support\Concerns;

use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Support\CurrentBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every business-scoped model. Filters reads to the current business
 * and stamps business_id on create, so no caller has to remember either.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function ($model) {
            if ($model->business_id === null) {
                $model->business_id = app(CurrentBusiness::class)->id();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Query across every business.
     *
     * Authorize before calling this. It exists for platform-admin reporting and
     * for maintenance commands, and nothing else should use it.
     */
    public function scopeAcrossAllBusinesses(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BusinessScope::class);
    }

    public function scopeForBusiness(Builder $query, Business|int $business): Builder
    {
        return $query
            ->withoutGlobalScope(BusinessScope::class)
            ->where($this->getTable() . '.business_id', $business instanceof Business ? $business->id : $business);
    }
}
